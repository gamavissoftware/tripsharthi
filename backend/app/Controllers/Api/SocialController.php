<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\SocialAccountModel;
use App\Models\SocialPostModel;
use App\Services\Auth\CurrentUser;
use App\Services\Social\SocialPublisher;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Social Planner — connected pages + scheduled posts.
 *
 * The page access token is validated against the Graph API at connect time,
 * stored encrypted, and never returned in any response.
 */
class SocialController extends ResourceController
{
    protected $format = 'json';

    // ── Accounts ────────────────────────────────────────────────────────

    public function accounts(): ResponseInterface
    {
        $model = (new SocialAccountModel())->setTenant(CurrentUser::tenantId());
        $rows  = array_map(
            static fn ($r) => (new SocialAccountModel())->publicRow((array) $r),
            $model->findAll()
        );

        return $this->respond(['success' => true, 'data' => $rows]);
    }

    public function connect(): ResponseInterface
    {
        $rules = [
            'page_id'      => 'required|max_length[50]',
            'access_token' => 'required|min_length[20]',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $pageId = trim((string) $this->request->getJsonVar('page_id'));
        $token  = trim((string) $this->request->getJsonVar('access_token'));

        // Validate against the Graph API before storing anything.
        try {
            $info = (new SocialPublisher())->validatePage($pageId, $token);
        } catch (\Throwable $e) {
            return $this->fail(['error' => 'Page validation failed: ' . $e->getMessage()], 422);
        }

        $payload = [
            'page_id'          => $pageId,
            'page_name'        => $info['page_name'],
            'ig_user_id'       => $info['ig_user_id'],
            'ig_username'      => $info['ig_username'],
            'access_token_enc' => TokenCipher::encrypt($token),
            'connected_by'     => CurrentUser::id(),
            // Flagged as hand-pasted so the UI can warn that this kind of token
            // usually expires — unlike one obtained through Facebook Login.
            'connect_method'   => 'manual',
            'status'           => 'active',
            'last_error'       => null,
        ];

        // Revives a previously disconnected page rather than colliding with the
        // soft-deleted row still holding (tenant_id, page_id).
        $id = (new SocialAccountModel())->upsertPage(CurrentUser::tenantId(), $pageId, $payload);

        $row = (array) (new SocialAccountModel())->setTenant(CurrentUser::tenantId())->find($id);

        return $this->respondCreated(['success' => true, 'data' => (new SocialAccountModel())->publicRow($row)]);
    }

    public function deleteAccount($id = null): ResponseInterface
    {
        $model = (new SocialAccountModel())->setTenant(CurrentUser::tenantId());
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Social account #{$id} not found.");
        }
        $model->setTenant(CurrentUser::tenantId())->delete((int) $id);

        return $this->respondDeleted(['success' => true]);
    }

    // ── Posts ───────────────────────────────────────────────────────────

    public function posts(): ResponseInterface
    {
        $model = (new SocialPostModel())->setTenant(CurrentUser::tenantId());
        $rows  = $model->orderBy('scheduled_at', 'DESC')->findAll(200);

        return $this->respond(['success' => true, 'data' => $rows]);
    }

    /**
     * Create scheduled posts — one row per requested platform.
     * Body: { social_account_id, platforms: ["facebook","instagram"],
     *         message, image_url?, scheduled_at }  (scheduled_at 'Y-m-d H:i')
     */
    public function createPost(): ResponseInterface
    {
        $rules = [
            'social_account_id' => 'required|is_natural_no_zero',
            'message'           => 'required|max_length[5000]',
            'scheduled_at'      => 'required',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId  = CurrentUser::tenantId();
        $accountId = (int) $this->request->getJsonVar('social_account_id');
        $platforms = (array) ($this->request->getJsonVar('platforms') ?? []);
        $platforms = array_values(array_intersect(['facebook', 'instagram'], array_map('strval', $platforms)));
        $message   = (string) $this->request->getJsonVar('message');
        $imageUrl  = trim((string) ($this->request->getJsonVar('image_url') ?? '')) ?: null;

        if ($platforms === []) {
            return $this->fail(['platforms' => 'Pick at least one platform.'], 422);
        }

        $account = (new SocialAccountModel())->setTenant($tenantId)->find($accountId);
        if (! $account) {
            return $this->failNotFound("Social account #{$accountId} not found.");
        }
        $account = (array) $account;

        if (in_array('instagram', $platforms, true)) {
            if (empty($account['ig_user_id'])) {
                return $this->fail(['platforms' => 'This page has no linked Instagram Business account.'], 422);
            }
            if ($imageUrl === null) {
                return $this->fail(['image_url' => 'Instagram posts require an image URL.'], 422);
            }
        }

        if ($imageUrl !== null && ! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return $this->fail(['image_url' => 'Image URL is not a valid URL.'], 422);
        }

        $when = strtotime((string) $this->request->getJsonVar('scheduled_at'));
        if ($when === false) {
            return $this->fail(['scheduled_at' => 'Invalid date/time.'], 422);
        }

        $model   = new SocialPostModel();
        $created = [];
        foreach ($platforms as $platform) {
            $id = $model->setTenant($tenantId)->insert([
                'social_account_id' => $accountId,
                'platform'          => $platform,
                'message'           => $message,
                'image_url'         => $imageUrl,
                'scheduled_at'      => date('Y-m-d H:i:s', $when),
                'status'            => 'scheduled',
            ], true);
            $created[] = (new SocialPostModel())->setTenant($tenantId)->find((int) $id);
        }

        return $this->respondCreated(['success' => true, 'data' => $created]);
    }

    public function deletePost($id = null): ResponseInterface
    {
        $model = (new SocialPostModel())->setTenant(CurrentUser::tenantId());
        $post  = $model->find((int) $id);
        if (! $post) {
            return $this->failNotFound("Post #{$id} not found.");
        }
        if (((array) $post)['status'] === 'publishing') {
            return $this->fail(['error' => 'Post is being published right now.'], 409);
        }
        $model->setTenant(CurrentUser::tenantId())->delete((int) $id);

        return $this->respondDeleted(['success' => true]);
    }
}
