<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\AdCredentialsService;
use App\Services\Travel\ConversionFeedbackService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class AdCredentialsServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /** @var list<array{0:string,1:string,2:array}> recorded HTTP calls */
    private array $calls = [];
    /** @var list<array{status:int,body:string}> queued fake responses */
    private array $queue = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['integrations', 'social_oauth_states', 'conversion_events', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'T', 'slug' => 't', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'O', 'email' => 'o@t.test', 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        $this->calls = $this->queue = [];
        foreach (['GOOGLE_ADS_CLIENT_ID' => 'cid', 'GOOGLE_ADS_CLIENT_SECRET' => 'csecret', 'CONVERSIONS_MOCK_MODE' => 'false'] as $k => $v) { putenv("{$k}={$v}"); $_ENV[$k] = $v; }
    }

    private function svc(): AdCredentialsService
    {
        return new AdCredentialsService(function (string $m, string $u, array $o): array {
            $this->calls[] = [$m, $u, $o];
            return array_shift($this->queue) ?? ['status' => 200, 'body' => '{}'];
        });
    }

    private function resp(int $status, array $body): void { $this->queue[] = ['status' => $status, 'body' => json_encode($body)]; }

    private function rawConfig(string $type): ?string
    {
        $r = db_connect()->table('integrations')->where('type', $type)->get()->getRowArray();
        return $r['config'] ?? null;
    }

    // ---- Meta -------------------------------------------------------------

    public function testMetaIsValidatedThenStoredEncryptedAndNeverReturned(): void
    {
        $this->resp(200, ['id' => '123456789', 'name' => 'Demo Travels Pixel']);
        $st = $this->svc()->saveMeta(1, ['dataset_id' => '123456789', 'access_token' => 'EAAB-secret-token', 'test_event_code' => 'TEST123']);

        $this->assertTrue($st['connected']);
        $this->assertSame('Demo Travels Pixel', $st['dataset_name']);
        $this->assertStringNotContainsString('EAAB', json_encode($st));
        $this->assertStringNotContainsString('EAAB', (string) $this->rawConfig('meta_capi'));           // not stored in clear
        $this->assertStringContainsString('graph.facebook.com', $this->calls[0][1]);
        $this->assertTrue((new ConversionFeedbackService())->config(1, 'meta_capi')['access_token'] === 'EAAB-secret-token'); // sender can decrypt it
    }

    public function testRejectedMetaCredentialsAreNotSaved(): void
    {
        $this->resp(400, ['error' => ['message' => 'Invalid OAuth access token.']]);
        try {
            $this->svc()->saveMeta(1, ['dataset_id' => '123', 'access_token' => 'bad']);
            $this->fail('expected rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid OAuth access token', $e->getMessage());
        }
        $this->assertNull($this->rawConfig('meta_capi'));
    }

    public function testUpdatingWithoutTokenKeepsSavedToken(): void
    {
        $this->resp(200, ['id' => '123', 'name' => 'P']);
        $this->svc()->saveMeta(1, ['dataset_id' => '123', 'access_token' => 'tok-1']);
        $this->resp(200, ['id' => '123', 'name' => 'P']);
        $this->svc()->saveMeta(1, ['dataset_id' => '123', 'test_event_code' => 'TEST9']);
        $cfg = (new ConversionFeedbackService())->config(1, 'meta_capi');
        $this->assertSame('tok-1', $cfg['access_token']);
        $this->assertSame('TEST9', $cfg['test_event_code']);
    }

    public function testMetaTestEventNeedsTestCodeAndUsesIt(): void
    {
        $this->resp(200, ['id' => '123', 'name' => 'P']);
        $this->svc()->saveMeta(1, ['dataset_id' => '123', 'access_token' => 'tok']);
        try { $this->svc()->testMeta(1); $this->fail('should require a test code'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Test Event Code', $e->getMessage()); }

        $this->resp(200, ['id' => '123', 'name' => 'P']);
        $this->svc()->saveMeta(1, ['dataset_id' => '123', 'test_event_code' => 'TEST55']);
        $this->resp(200, ['events_received' => 1, 'fbtrace_id' => 'abc']);
        $out = $this->svc()->testMeta(1);
        $this->assertSame(1, $out['events_received']);
        $sent = end($this->calls)[2]['json'];
        $this->assertSame('TEST55', $sent['test_event_code']);
        $this->assertSame('Lead', $sent['data'][0]['event_name']);
    }

    // ---- Google -------------------------------------------------------------

    public function testGoogleStartRequiresServerConfigAndMintsSingleUseState(): void
    {
        $url = $this->svc()->googleStart(1, 1);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('adwords', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->resp(200, ['refresh_token' => '1//refresh-secret', 'access_token' => 'a']);
        $this->svc()->googleCallback('the-code', $q['state']);
        $st = $this->svc()->status(1)['google'];
        $this->assertTrue($st['connected']);
        $this->assertStringNotContainsString('refresh-secret', (string) $this->rawConfig('google_ads'));
        $this->assertStringNotContainsString('refresh-secret', json_encode($st));

        $this->expectException(\RuntimeException::class);              // replaying the same state must fail
        $this->svc()->googleCallback('the-code', $q['state']);
    }

    public function testGoogleCallbackRejectsForgedAndExpiredState(): void
    {
        try { $this->svc()->googleCallback('c', str_repeat('a', 48)); $this->fail('forged state accepted'); } catch (\RuntimeException) { $this->addToAssertionCount(1); }

        $url = $this->svc()->googleStart(1, 1);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        db_connect()->table('social_oauth_states')->where('state', $q['state'])->update(['expires_at' => date('Y-m-d H:i:s', time() - 5)]);
        $this->expectException(\RuntimeException::class);
        $this->svc()->googleCallback('c', $q['state']);
        $this->assertSame(0, count($this->calls));                     // never reached Google with a bad state
    }

    public function testGoogleCallbackWithoutRefreshTokenGivesActionableError(): void
    {
        parse_str((string) parse_url($this->svc()->googleStart(1, 1), PHP_URL_QUERY), $q);
        $this->resp(200, ['access_token' => 'a']);                     // no refresh_token
        $this->expectExceptionMessage('myaccount.google.com/permissions');
        $this->svc()->googleCallback('c', $q['state']);
    }

    public function testConversionMappingMustBelongToSelectedAccount(): void
    {
        parse_str((string) parse_url($this->svc()->googleStart(1, 1), PHP_URL_QUERY), $q);
        $this->resp(200, ['refresh_token' => 'r']);
        $this->svc()->googleCallback('c', $q['state']);

        try { $this->svc()->googleSelect(1, '12345'); $this->fail('short id accepted'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->svc()->googleSelect(1, '123-456-7890');

        $st = $this->svc()->saveGoogleMapping(1, ['Purchase' => 'customers/1234567890/conversionActions/222']);
        $this->assertSame('customers/1234567890/conversionActions/222', ((array) $st['conversion_actions'])['Purchase']);

        $cleared = $this->svc()->googleSelect(1, '');                  // "Change account" keeps the connection, drops the account + mapping
        $this->assertTrue($cleared['connected']);
        $this->assertSame('', $cleared['customer_id']);
        $this->svc()->googleSelect(1, '1234567890');

        $this->expectException(\InvalidArgumentException::class);      // another account's action
        $this->svc()->saveGoogleMapping(1, ['Purchase' => 'customers/9999999999/conversionActions/1']);
    }

    // ---- disconnect / log -----------------------------------------------------

    public function testDisconnectRemovesCredentials(): void
    {
        $this->resp(200, ['id' => '123', 'name' => 'P']);
        $this->svc()->saveMeta(1, ['dataset_id' => '123', 'access_token' => 'tok']);
        $this->svc()->disconnect(1, 'meta');
        $this->assertNull($this->rawConfig('meta_capi'));
        $this->assertFalse($this->svc()->status(1)['meta']['connected']);
    }

    public function testRetryOnlyFailedOrSkippedEvents(): void
    {
        $base = ['tenant_id' => 1, 'platform' => 'meta', 'event_name' => 'Lead', 'event_time' => '2026-10-08 10:00:00', 'created_at' => '2026-10-08 10:00:00'];
        db_connect()->table('conversion_events')->insert($base + ['id' => 1, 'event_id' => 'a', 'status' => 'failed', 'attempts' => 5, 'response' => 'boom']);
        db_connect()->table('conversion_events')->insert($base + ['id' => 2, 'event_id' => 'b', 'status' => 'sent']);
        $this->assertTrue($this->svc()->retry(1, 1));
        $this->assertFalse($this->svc()->retry(1, 2));
        $row = db_connect()->table('conversion_events')->where('id', 1)->get()->getRowArray();
        $this->assertSame('pending', $row['status']);
        $this->assertSame('0', (string) $row['attempts']);
    }

    public function testEventToggleFilter(): void
    {
        $this->assertTrue(ConversionFeedbackService::eventEnabled([], 'Lead'));
        $this->assertTrue(ConversionFeedbackService::eventEnabled(['events' => ['Lead']], 'Lead'));
        $this->assertFalse(ConversionFeedbackService::eventEnabled(['events' => ['Purchase']], 'Lead'));
    }
}
