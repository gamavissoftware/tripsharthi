<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\WhatsappOversightService;
use App\Services\WhatsApp\SendingGate;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Cross-workspace WhatsApp oversight + the marketing pause, against real MySQL (group "mysql"). */
#[Group('mysql')]
final class WhatsappOversightTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['messages', 'conversations', 'campaigns', 'templates', 'phone_numbers', 'waba_accounts', 'waba_billing', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $names = [1 => 'Alpha Travels', 2 => 'Beta Holidays', 3 => 'Gamma Tours', 4 => 'Delta Trips', 5 => 'Epsilon (no WhatsApp)'];
        foreach ($names as $id => $n) {
            $db->table('tenants')->insert(['id' => $id, 'name' => $n, 'slug' => 'w' . $id, 'plan' => 'growth', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
            $db->table('users')->insert(['tenant_id' => $id, 'name' => "Owner $id", 'email' => "o{$id}@example.com", 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
        }
        // 1 = healthy (green), 2 = yellow + rejected templates + lots of failures, 3 = red + suspended, 4 = pending connection, 5 = nothing
        $this->waba(1, 'active', ['green']); $this->waba(2, 'active', ['green', 'yellow']); $this->waba(3, 'suspended', ['red']); $this->waba(4, 'pending', []);
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function waba(int $tenant, string $status, array $ratings): void
    {
        $db = db_connect();
        $db->table('waba_accounts')->insert(['tenant_id' => $tenant, 'waba_id' => 'waba' . $tenant, 'display_name' => "WABA {$tenant}", 'access_token_enc' => 'SECRET-TOKEN-' . $tenant, 'status' => $status, 'provider' => 'cloud', 'created_at' => '2026-01-02 00:00:00']);
        $wid = (int) $db->insertID();
        foreach ($ratings as $i => $q) { $db->table('phone_numbers')->insert(['waba_account_id' => $wid, 'tenant_id' => $tenant, 'phone_number_id' => "pn{$tenant}_{$i}", 'display_number' => "+9199000{$tenant}0{$i}", 'quality_rating' => $q]); }
    }

    private function msgs(int $tenant, int $n, string $status, string $category = 'service', int $daysAgo = 2, ?string $error = null): void
    {
        $db = db_connect(); $at = date('Y-m-d H:i:s', time() - $daysAgo * 86400);
        for ($i = 0; $i < $n; $i++) {
            $db->table('messages')->insert(['tenant_id' => $tenant, 'conversation_id' => 1, 'direction' => 'out', 'type' => 'text', 'category' => $category, 'status' => $status, 'error' => $error, 'created_at' => $at, 'updated_at' => $at]);
        }
    }

    private function row(array $o, int $id): array { return array_values(array_filter($o['rows'], fn ($r) => $r['id'] === $id))[0]; }
    private function svc(): WhatsappOversightService { return new WhatsappOversightService(); }

    public function testEveryWorkspaceIsListedWithItsWhatsappStateAndWorstQuality(): void
    {
        $o = $this->svc()->overview();
        $this->assertSame(5, $o['total']);
        $this->assertSame(['active', 'green'], [$this->row($o, 1)['whatsapp'], $this->row($o, 1)['quality']]);
        $this->assertSame('yellow', $this->row($o, 2)['quality'], 'the worst number decides');
        $this->assertSame(['suspended', 'red'], [$this->row($o, 3)['whatsapp'], $this->row($o, 3)['quality']]);
        $this->assertSame(['pending', 'none'], [$this->row($o, 4)['whatsapp'], $this->row($o, 4)['quality']]);
        $this->assertSame(['none', 'none'], [$this->row($o, 5)['whatsapp'], $this->row($o, 5)['quality']]);
    }

    public function testVolumeDeliveryFailureAndMarketingAreCountedOverTheLast30DaysOnly(): void
    {
        $this->msgs(1, 40, 'read', 'marketing'); $this->msgs(1, 10, 'delivered', 'utility'); $this->msgs(1, 5, 'failed', 'marketing'); $this->msgs(1, 4, 'sent');
        $this->msgs(1, 99, 'failed', 'marketing', 45);                    // older than the window: ignored
        $r = $this->row($this->svc()->overview(), 1);
        $this->assertSame([59, 50, 40, 5, 45], [$r['sent'], $r['delivered'], $r['read'], $r['failed'], $r['marketing']]);
        $this->assertSame(8.5, $r['failure_pct']);
    }

    public function testFlagsFollowTheSeverityRules(): void
    {
        $this->msgs(1, 60, 'read');                                                            // healthy: no flags
        $this->msgs(2, 40, 'delivered'); $this->msgs(2, 20, 'failed', 'marketing');            // 33% failures, yellow, rejected templates
        db_connect()->table('templates')->insertBatch(array_map(fn ($i) => ['tenant_id' => 2, 'name' => "t{$i}", 'language' => 'en', 'category' => 'marketing', 'body' => 'x', 'meta_status' => 'rejected', 'rejection_reason' => 'INVALID_FORMAT', 'created_at' => '2026-01-01 00:00:00'], [1, 2, 3]));
        $this->msgs(4, 30, 'failed');                                                          // too few messages for a rate to mean anything? 30 < 50 -> no failure flag
        $o = $this->svc()->overview();
        $this->assertSame([], $this->row($o, 1)['flags']);
        $this->assertNull($this->row($o, 1)['attention']);
        $codes2 = array_column($this->row($o, 2)['flags'], 'severity', 'code');
        $this->assertSame(['quality_yellow' => 'warning', 'high_failures' => 'critical', 'rejected_templates' => 'warning'], $codes2);
        $this->assertSame('critical', $this->row($o, 2)['attention']);
        $this->assertEqualsCanonicalizing(['quality_red', 'waba_suspended'], array_column($this->row($o, 3)['flags'], 'code'));
        $this->assertSame([], array_column($this->row($o, 4)['flags'], 'code'), 'a small sample never raises a failure-rate alarm');
    }

    public function testFiltersSearchAndTheAttentionFirstOrdering(): void
    {
        $this->msgs(1, 60, 'read');
        $o = $this->svc()->overview();
        $this->assertSame([3, 2], array_slice(array_column($o['rows'], 'id'), 0, 2), 'critical (red + suspended) ranks above warning (yellow)');
        $this->assertEqualsCanonicalizing([2, 3], array_column($this->svc()->overview('', 'attention')['rows'], 'id'));
        $this->assertEqualsCanonicalizing([1, 2], array_column($this->svc()->overview('', 'connected')['rows'], 'id'));
        $this->assertSame([5], array_column($this->svc()->overview('', 'not_connected')['rows'], 'id'));
        $this->assertSame([2], array_column($this->svc()->overview('beta')['rows'], 'id'));
        $this->assertSame([3], array_column($this->svc()->overview('3')['rows'], 'id'), 'also findable by id');
    }

    public function testTotalsSummariseThePlatform(): void
    {
        $this->msgs(1, 60, 'read'); $this->msgs(2, 40, 'delivered'); $this->msgs(2, 20, 'failed');
        db_connect()->table('templates')->insertBatch([['tenant_id' => 1, 'name' => 'a', 'language' => 'en', 'category' => 'utility', 'body' => 'x', 'meta_status' => 'pending', 'created_at' => '2026-01-01 00:00:00'], ['tenant_id' => 2, 'name' => 'b', 'language' => 'en', 'category' => 'utility', 'body' => 'x', 'meta_status' => 'rejected', 'created_at' => '2026-01-01 00:00:00']]);
        db_connect()->table('waba_billing')->insert(['tenant_id' => 1, 'waba_id' => 'waba1', 'month' => date('Y-m'), 'category' => 'marketing', 'pricing_type' => 'regular', 'volume' => 60, 'cost' => 47.5, 'currency' => 'INR']);
        $t = $this->svc()->overview()['totals'];
        $this->assertSame(5, $t['workspaces']);
        $this->assertSame(2, $t['connected'], 'tenants 1 and 2 are active');
        $this->assertSame(1, $t['not_connected'], 'only tenant 5 has no WhatsApp at all (4 is still pending)');
        $this->assertSame([1, 0], [$t['suspended'], $t['paused']]);
        $this->assertSame([120, 100, 20], [$t['sent'], $t['delivered'], $t['failed']]);
        $this->assertSame([83.3, 16.7], [$t['delivery_pct'], $t['failure_pct']]);
        $this->assertSame(['green' => 1, 'yellow' => 1, 'red' => 1, 'unknown' => 0], $t['quality']);
        $this->assertSame([1, 1, 47.5], [$t['templates_pending'], $t['templates_rejected'], $t['cost_this_month']]);
    }

    public function testTheWorkspaceDetailCarriesTemplatesDailyVolumeErrorsAndBilling(): void
    {
        $this->msgs(2, 8, 'failed', 'marketing', 1, '(#131026) Message undeliverable');
        $this->msgs(2, 3, 'failed', 'marketing', 1, '(#130472) Number part of an experiment');
        $this->msgs(2, 5, 'read', 'marketing', 2);
        db_connect()->table('templates')->insert(['tenant_id' => 2, 'name' => 'promo', 'language' => 'en', 'category' => 'marketing', 'body' => 'x', 'meta_status' => 'rejected', 'rejection_reason' => 'INVALID_FORMAT', 'created_at' => '2026-01-01 00:00:00']);
        db_connect()->table('waba_billing')->insert(['tenant_id' => 2, 'waba_id' => 'waba2', 'month' => date('Y-m'), 'category' => 'marketing', 'pricing_type' => 'regular', 'volume' => 16, 'cost' => 12.0, 'currency' => 'INR']);
        $d = $this->svc()->workspace(2);
        $this->assertSame('Beta Holidays', $d['workspace']['name']);
        $this->assertSame('o2@example.com', $d['owner']['email']);
        $this->assertSame(['promo', 'rejected', 'INVALID_FORMAT'], [$d['templates'][0]['name'], $d['templates'][0]['meta_status'], $d['templates'][0]['rejection_reason']]);
        $this->assertSame([['(#131026) Message undeliverable', 8], ['(#130472) Number part of an experiment', 3]], array_map(fn ($e) => [$e['err'], (int) $e['n']], $d['errors']), 'most common failures first');
        $this->assertSame([2, (int) 5], [count($d['daily']), (int) $d['daily'][0]['sent']]);
        $this->assertSame(12.0, (float) $d['billing'][0]['cost']);
    }

    public function testNoAccessTokenOrMessageBodyEverLeavesTheService(): void
    {
        $this->msgs(1, 3, 'read');
        $json = json_encode([$this->svc()->overview(), $this->svc()->workspace(1)]);
        foreach (['SECRET-TOKEN', 'access_token', 'password_hash', 'o1@example.com'] as $leak) {
            if ($leak === 'o1@example.com') { continue; }          // the owner's email IS shown on the detail page (the team needs to contact them)
            $this->assertStringNotContainsString($leak, $json, "oversight must not expose {$leak}");
        }
        $this->assertStringNotContainsString('SECRET-TOKEN', json_encode($this->svc()->overview()), 'the overview carries no owner contact details at all');
        $this->assertStringNotContainsString('o1@example.com', json_encode($this->svc()->overview()));
    }

    public function testUnknownWorkspaceIs404(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->svc()->workspace(999);
    }

    // ---- the marketing pause ---------------------------------------------------------------------------------------------------------

    public function testPausingMarketingNeedsAReasonIsAuditedAndStopsTheSendingGate(): void
    {
        $gate = new SendingGate();
        $this->assertNull($gate->blockedReason(1), 'healthy workspace may send marketing');
        try { $this->svc()->setMarketingPaused(1, true, 'no', 7); $this->fail('short reason accepted'); } catch (\InvalidArgumentException) { /* expected */ }

        $r = $this->svc()->setMarketingPaused(1, true, 'Spam complaints from customers', 7);
        $this->assertTrue($r['paused']);
        $reason = $gate->blockedReason(1);
        $this->assertStringContainsString('Marketing paused by TripSarthi', (string) $reason);
        $this->assertStringContainsString('Spam complaints', (string) $reason);
        $this->assertStringContainsString('Utility messages', (string) $reason, 'the workspace is told booking confirmations still go out');
        $this->assertFalse($gate->allowsMarketing(1));
        $this->assertTrue($gate->allowsMarketing(5), 'other workspaces are unaffected');
        $this->assertSame(1, (int) db_connect()->table('audit_logs')->where('action', 'admin.wa_marketing.pause')->countAllResults());
        $this->assertSame(['info'], array_column($this->row($this->svc()->overview(), 1)['flags'], 'severity'));

        $this->svc()->setMarketingPaused(1, false, '', 7);
        $this->assertNull($gate->blockedReason(1), 'resuming lifts the pause at once');
        $this->assertSame(1, (int) db_connect()->table('audit_logs')->where('action', 'admin.wa_marketing.resume')->countAllResults());
    }

    public function testPausedFilterAndUnknownTenant(): void
    {
        $this->svc()->setMarketingPaused(2, true, 'Abuse reports under review', 7);
        $this->assertSame([2], array_column($this->svc()->overview('', 'paused')['rows'], 'id'));
        $this->expectException(\OutOfBoundsException::class);
        $this->svc()->setMarketingPaused(999, true, 'does not exist anywhere', 7);
    }
}
