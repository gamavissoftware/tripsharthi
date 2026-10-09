<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialAccountModel;
use App\Models\SocialPostModel;
use RuntimeException;

/**
 * Publishes scheduled social posts to Facebook Pages / Instagram Business.
 *
 * Dispatch contract (mirrors ScheduledCampaignDispatcher):
 *   dispatchDue() claims every 'scheduled' post whose scheduled_at has passed
 *   by flipping it to 'publishing' first (the claim is an UPDATE with a WHERE
 *   on the old status, so two overlapping dispatchers cannot double-publish),
 *   then publishes and records 'published' + platform_post_id, or 'failed' + error.
 *
 * Platform rules:
 *   - Facebook: text-only → POST /{page}/feed, with image → POST /{page}/photos
 *   - Instagram: image REQUIRED — two-step container create + media_publish
 */
class SocialPublisher
{
    public function __construct(private ?GraphClient $graph = null)
    {
        $this->graph ??= new GraphClient();
    }

    /**
     * Validate a page connection at connect time. Returns page name + linked
     * Instagram Business account (if any). Throws on a bad id/token.
     *
     * @return array{page_name:string, ig_user_id:?string, ig_username:?string}
     */
    public function validatePage(string $pageId, string $accessToken): array
    {
        $page = $this->graph->get($pageId, [
            'fields'       => 'name,instagram_business_account{id,username}',
            'access_token' => $accessToken,
        ]);

        return [
            'page_name'   => (string) ($page['name'] ?? $pageId),
            'ig_user_id'  => $page['instagram_business_account']['id'] ?? null,
            'ig_username' => $page['instagram_business_account']['username'] ?? null,
        ];
    }

    /**
     * Claim + publish all due scheduled posts.
     *
     * @return array{published:int, failed:int, ids:array<int>}
     */
    public function dispatchDue(?int $now = null): array
    {
        $now ??= time();
        $posts = new SocialPostModel();

        $due = $posts->withoutTenantScope()
                     ->where('status', 'scheduled')
                     ->where('scheduled_at <=', date('Y-m-d H:i:s', $now))
                     ->orderBy('scheduled_at', 'ASC')
                     ->findAll(50);

        $result = ['published' => 0, 'failed' => 0, 'ids' => []];

        foreach ($due as $post) {
            $post = (array) $post;
            $id   = (int) $post['id'];

            // Idempotent claim: only proceed if WE flipped scheduled → publishing.
            $claimed = $posts->db->table('social_posts')
                                 ->where('id', $id)
                                 ->where('status', 'scheduled')
                                 ->update(['status' => 'publishing', 'updated_at' => date('Y-m-d H:i:s')]);
            if (! $claimed || $posts->db->affectedRows() === 0) {
                continue;
            }

            try {
                $platformPostId = $this->publish($post);
                $posts->withoutTenantScope()->update($id, [
                    'status'           => 'published',
                    'platform_post_id' => $platformPostId,
                    'published_at'     => date('Y-m-d H:i:s'),
                    'error'            => null,
                ]);
                $result['published']++;
            } catch (\Throwable $e) {
                $posts->withoutTenantScope()->update($id, [
                    'status' => 'failed',
                    'error'  => mb_substr($e->getMessage(), 0, 2000),
                ]);
                // A dead token fails every future post too, so mark the account
                // rather than letting the whole queue drain into 'failed' with
                // nothing on screen explaining why.
                $this->flagAccountIfCredentialDead((int) $post['social_account_id'], $e);
                log_message('error', '[social:dispatch] post #' . $id . ' failed: ' . $e->getMessage());
                $result['failed']++;
            }

            $result['ids'][] = $id;
        }

        return $result;
    }

    /**
     * Flip a page to 'reauth_required' when Graph says the credential is dead.
     *
     * Only credential failures count: a bad image URL or an over-long caption
     * must not disconnect a healthy page. The error text is kept on the row so
     * the Social page can say what happened instead of just "reconnect".
     */
    private function flagAccountIfCredentialDead(int $accountId, \Throwable $e): void
    {
        if (! $e instanceof GraphApiException || ! $e->requiresReauth()) {
            return;
        }

        try {
            (new SocialAccountModel())->withoutTenantScope()->update($accountId, [
                'status'     => 'reauth_required',
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            log_message('error', sprintf(
                '[social:dispatch] account #%d needs reconnecting (Graph code %d): %s',
                $accountId,
                $e->graphCode,
                $e->getMessage()
            ));
        } catch (\Throwable $inner) {
            // Never let bookkeeping mask the original publish failure.
            log_message('error', '[social:dispatch] could not flag account #' . $accountId . ': ' . $inner->getMessage());
        }
    }

    /**
     * Publish one post row. Returns the platform post id.
     */
    public function publish(array $post): string
    {
        $accounts = new SocialAccountModel();
        $account  = $accounts->withoutTenantScope()->find((int) $post['social_account_id']);
        if (! $account) {
            throw new RuntimeException('Social account #' . $post['social_account_id'] . ' not found.');
        }
        $account = (array) $account;
        $token   = $accounts->getDecryptedToken($account);
        if ($token === '') {
            throw new RuntimeException('Social account has no access token.');
        }

        return $post['platform'] === 'instagram'
            ? $this->publishInstagram($account, $post, $token)
            : $this->publishFacebook($account, $post, $token);
    }

    private function publishFacebook(array $account, array $post, string $token): string
    {
        $pageId = (string) $account['page_id'];

        if (! empty($post['image_url'])) {
            $res = $this->graph->post($pageId . '/photos', [
                'url'          => $post['image_url'],
                'caption'      => $post['message'],
                'access_token' => $token,
            ]);

            return (string) ($res['post_id'] ?? $res['id'] ?? '');
        }

        $res = $this->graph->post($pageId . '/feed', [
            'message'      => $post['message'],
            'access_token' => $token,
        ]);

        return (string) ($res['id'] ?? '');
    }

    private function publishInstagram(array $account, array $post, string $token): string
    {
        $igUserId = (string) ($account['ig_user_id'] ?? '');
        if ($igUserId === '') {
            throw new RuntimeException('This page has no linked Instagram Business account.');
        }
        if (empty($post['image_url'])) {
            throw new RuntimeException('Instagram posts require an image.');
        }

        // Step 1 — create the media container
        $container = $this->graph->post($igUserId . '/media', [
            'image_url'    => $post['image_url'],
            'caption'      => $post['message'],
            'access_token' => $token,
        ]);
        $creationId = (string) ($container['id'] ?? '');
        if ($creationId === '') {
            throw new RuntimeException('Instagram media container creation returned no id.');
        }

        // Step 2 — publish the container
        $res = $this->graph->post($igUserId . '/media_publish', [
            'creation_id'  => $creationId,
            'access_token' => $token,
        ]);

        return (string) ($res['id'] ?? '');
    }
}
