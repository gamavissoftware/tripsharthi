<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Social\GraphApiException;
use App\Services\Social\GraphClient;
use App\Services\Social\SocialPublisher;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Social Planner dispatch + publish rules.
 *
 * Uses a FakeGraphClient (records calls, returns canned responses) — no HTTP.
 * Tables are dropped + recreated in setUp so the shared :memory: DB cannot
 * leak schema/rows from or to other suites (random-order safe).
 */
class SocialPublisherTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $p  = $db->DBPrefix;

        $db->query("DROP TABLE IF EXISTS {$p}social_posts");
        $db->query("DROP TABLE IF EXISTS {$p}social_accounts");

        $db->query("CREATE TABLE {$p}social_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            page_id TEXT, page_name TEXT, ig_user_id TEXT, ig_username TEXT,
            access_token_enc TEXT, token_expires_at TEXT, connected_by INTEGER,
            connect_method TEXT DEFAULT 'manual', status TEXT DEFAULT 'active',
            last_error TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE {$p}social_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            social_account_id INTEGER, platform TEXT, message TEXT, image_url TEXT,
            scheduled_at TEXT, status TEXT DEFAULT 'scheduled',
            platform_post_id TEXT, error TEXT, published_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
    }

    private function makeAccount(array $overrides = []): int
    {
        $db = db_connect();
        $db->table('social_accounts')->insert(array_merge([
            'tenant_id'        => 1,
            'page_id'          => '111222333',
            'page_name'        => 'Test Page',
            'ig_user_id'       => '17840000000',
            'ig_username'      => 'testbiz',
            'access_token_enc' => TokenCipher::encrypt('raw-page-token'),
            'status'           => 'active',
            'created_at'       => date('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $db->insertID();
    }

    private function makePost(int $accountId, array $overrides = []): int
    {
        $db = db_connect();
        $db->table('social_posts')->insert(array_merge([
            'tenant_id'         => 1,
            'social_account_id' => $accountId,
            'platform'          => 'facebook',
            'message'           => 'Hello world',
            'image_url'         => null,
            'scheduled_at'      => date('Y-m-d H:i:s', time() - 60),
            'status'            => 'scheduled',
            'created_at'        => date('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $db->insertID();
    }

    public function testDispatchPublishesDueFacebookTextPostViaFeedEdge(): void
    {
        $accountId = $this->makeAccount();
        $postId    = $this->makePost($accountId);

        $graph  = new FakeGraphClient(['/feed' => ['id' => 'fb_post_1']]);
        $result = (new SocialPublisher($graph))->dispatchDue();

        $this->assertSame(1, $result['published']);
        $this->assertSame(0, $result['failed']);

        $row = (array) db_connect()->table('social_posts')->where('id', $postId)->get()->getFirstRow();
        $this->assertSame('published', $row['status']);
        $this->assertSame('fb_post_1', $row['platform_post_id']);
        $this->assertNotEmpty($row['published_at']);

        // Text-only FB post must go to /feed with the message + decrypted token
        $call = $graph->calls[0];
        $this->assertSame('111222333/feed', $call['path']);
        $this->assertSame('Hello world', $call['params']['message']);
        $this->assertSame('raw-page-token', $call['params']['access_token']);
    }

    public function testDispatchUsesPhotosEdgeWhenImagePresent(): void
    {
        $accountId = $this->makeAccount();
        $this->makePost($accountId, ['image_url' => 'https://example.com/pic.jpg']);

        $graph = new FakeGraphClient(['/photos' => ['post_id' => 'fb_photo_9']]);
        (new SocialPublisher($graph))->dispatchDue();

        $call = $graph->calls[0];
        $this->assertSame('111222333/photos', $call['path']);
        $this->assertSame('https://example.com/pic.jpg', $call['params']['url']);
    }

    public function testInstagramPublishIsTwoStepContainerThenPublish(): void
    {
        $accountId = $this->makeAccount();
        $postId    = $this->makePost($accountId, [
            'platform'  => 'instagram',
            'image_url' => 'https://example.com/pic.jpg',
        ]);

        $graph = new FakeGraphClient([
            '/media_publish' => ['id' => 'ig_post_5'],
            '/media'         => ['id' => 'container_7'],
        ]);
        $result = (new SocialPublisher($graph))->dispatchDue();

        $this->assertSame(1, $result['published']);
        $this->assertSame('17840000000/media', $graph->calls[0]['path']);
        $this->assertSame('17840000000/media_publish', $graph->calls[1]['path']);
        $this->assertSame('container_7', $graph->calls[1]['params']['creation_id']);

        $row = (array) db_connect()->table('social_posts')->where('id', $postId)->get()->getFirstRow();
        $this->assertSame('ig_post_5', $row['platform_post_id']);
    }

    public function testInstagramWithoutImageFailsWithoutGraphCall(): void
    {
        $accountId = $this->makeAccount();
        $postId    = $this->makePost($accountId, ['platform' => 'instagram', 'image_url' => null]);

        $graph  = new FakeGraphClient([]);
        $result = (new SocialPublisher($graph))->dispatchDue();

        $this->assertSame(1, $result['failed']);
        $this->assertCount(0, $graph->calls);

        $row = (array) db_connect()->table('social_posts')->where('id', $postId)->get()->getFirstRow();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('require an image', $row['error']);
    }

    public function testGraphErrorMarksPostFailedAndStoresMessage(): void
    {
        $accountId = $this->makeAccount();
        $postId    = $this->makePost($accountId);

        $graph  = new FakeGraphClient([]); // no canned response → throws
        $result = (new SocialPublisher($graph))->dispatchDue();

        $this->assertSame(1, $result['failed']);
        $row = (array) db_connect()->table('social_posts')->where('id', $postId)->get()->getFirstRow();
        $this->assertSame('failed', $row['status']);
        $this->assertNotEmpty($row['error']);
    }

    public function testDeadTokenFlagsTheAccountForReconnection(): void
    {
        $accountId = $this->makeAccount();
        $this->makePost($accountId);

        $graph = new FakeGraphClient([]);
        $graph->throwGraphCode = 190;   // token expired / revoked
        (new SocialPublisher($graph))->dispatchDue();

        $account = (array) db_connect()->table('social_accounts')->where('id', $accountId)->get()->getFirstRow();
        $this->assertSame('reauth_required', $account['status']);
        $this->assertStringContainsString('190', $account['last_error']);
    }

    public function testAnOrdinaryPostErrorLeavesTheAccountAlone(): void
    {
        $accountId = $this->makeAccount();
        $this->makePost($accountId);

        // Code 100 is Meta's "invalid parameter" — an unreachable image URL,
        // say. It is typed OAuthException too, which is exactly why the code
        // and not the type decides. Disconnecting a healthy page over one bad
        // post would be worse than the failure itself.
        $graph = new FakeGraphClient([]);
        $graph->throwGraphCode = 100;
        (new SocialPublisher($graph))->dispatchDue();

        $account = (array) db_connect()->table('social_accounts')->where('id', $accountId)->get()->getFirstRow();
        $this->assertSame('active', $account['status']);
        $this->assertEmpty($account['last_error']);
    }

    public function testFuturePostsAndClaimedPostsAreNotDispatched(): void
    {
        $accountId = $this->makeAccount();
        $future    = $this->makePost($accountId, ['scheduled_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $claimed   = $this->makePost($accountId, ['status' => 'publishing']);

        $graph  = new FakeGraphClient(['/feed' => ['id' => 'x']]);
        $result = (new SocialPublisher($graph))->dispatchDue();

        $this->assertSame([], $result['ids']);
        $this->assertCount(0, $graph->calls);

        $db = db_connect();
        $this->assertSame('scheduled', ((array) $db->table('social_posts')->where('id', $future)->get()->getFirstRow())['status']);
        $this->assertSame('publishing', ((array) $db->table('social_posts')->where('id', $claimed)->get()->getFirstRow())['status']);
    }
}

/**
 * Records every call; returns the canned response whose key is a suffix of the
 * requested path, else throws like the real client does on a Graph error.
 */
class FakeGraphClient extends GraphClient
{
    /** @var array<int, array{method:string, path:string, params:array}> */
    public array $calls = [];

    /** Graph error code to raise instead of answering, as the real client would. */
    public ?int $throwGraphCode = null;
    public int $throwGraphSubcode = 0;

    public function __construct(private array $responses)
    {
    }

    protected function request(string $method, string $path, array $params): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $params];

        if ($this->throwGraphCode !== null) {
            throw new GraphApiException(
                'Graph API error ' . $this->throwGraphCode . ' (OAuthException): fake',
                $this->throwGraphCode,
                $this->throwGraphSubcode,
                'OAuthException'
            );
        }

        foreach ($this->responses as $suffix => $response) {
            if (str_ends_with($path, $suffix)) {
                return $response;
            }
        }

        throw new \RuntimeException('Graph API error 190 (OAuthException): fake — no canned response for ' . $path);
    }
}
