<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Admin\PlatformCrmService;
use App\Services\Admin\PlatformNurtureRecipes;
use App\Services\Admin\PlatformSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** TripSarthi's own CRM: setup, nurture graphs, funnel reconcile and the dashboard (group "mysql"). */
#[Group('mysql')]
final class PlatformCrmTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private const MK = 9;

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect(); $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['platform_settings', 'jobs', 'flow_runs', 'flows', 'templates', 'deals', 'pipeline_stages', 'pipelines', 'contact_tags', 'tags', 'contacts', 'tasks', 'activities', 'lead_attributions', 'emails', 'messages', 'subscriptions', 'integrations', 'audit_logs', 'users', 'tenants'] as $t) { $db->table($t)->truncate(); }
        foreach ([[self::MK, 'TripSarthi Marketing'], [1, 'Alpha Travels'], [2, 'Beta Holidays']] as [$id, $n]) {
            $db->table('tenants')->insert(['id' => $id, 'name' => $n, 'slug' => 'w' . $id, 'plan' => 'free', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        }
        $u = fn (int $t, string $e, string $p) => $db->table('users')->insert(['tenant_id' => $t, 'name' => 'U' . $t, 'email' => $e, 'password_hash' => 'x', 'role' => 'owner', 'phone' => $p, 'created_at' => '2026-01-01 00:00:00']);
        $u(self::MK, 'mk@tripsarthi.test', '9800000099'); $u(1, 'asha@alpha.test', '+91 98765 00001'); $u(2, 'bala@beta.test', '98765 00002');
        $db->table('subscriptions')->insert(['tenant_id' => 2, 'razorpay_sub_id' => 'pay_b', 'plan' => 'growth', 'amount_paise' => 399900, 'billing_cycle' => 'monthly', 'status' => 'active', 'current_period_start' => date('Y-m-d H:i:s', time() - 5 * 86400), 'current_period_end' => date('Y-m-d H:i:s', time() + 25 * 86400), 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        PlatformSettings::set(PlatformSettings::MARKETING_TENANT, (string) self::MK);
    }

    protected function tearDown(): void { db_connect()->query('SET FOREIGN_KEY_CHECKS=1'); parent::tearDown(); }

    private function lead(string $name, ?string $email, ?string $phone, array $extra = []): array
    {
        $db = db_connect();
        $db->table('contacts')->insert(['tenant_id' => self::MK, 'name' => $name, 'email' => $email, 'wa_number' => $phone, 'source' => 'meta_lead_ads', 'status' => 'new', 'opt_in' => 1, 'created_at' => date('Y-m-d H:i:s', time() - 7200)] + $extra);
        $cid = (int) $db->insertID();
        $pl = $db->table('pipelines')->where('tenant_id', self::MK)->get()->getRowArray();
        $st = $db->table('pipeline_stages')->where('pipeline_id', $pl['id'])->where('name', 'New lead')->get()->getRowArray();
        $db->table('deals')->insert(['tenant_id' => self::MK, 'title' => 'Facebook Ads lead', 'pipeline_id' => $pl['id'], 'stage_id' => $st['id'], 'primary_contact_id' => $cid, 'value_amount' => 0, 'status' => 'open', 'created_at' => date('Y-m-d H:i:s', time() - 7200)]);
        return ['contact' => $cid, 'deal' => (int) $db->insertID()];
    }

    private function stageOf(int $deal): string
    {
        return (string) db_connect()->query('SELECT s.name FROM deals d JOIN pipeline_stages s ON s.id = d.stage_id WHERE d.id = ?', [$deal])->getRow()->name;
    }

    public function testSetupBuildsPipelineTagsAndDraftFlowsAndIsIdempotent(): void
    {
        $a = (new PlatformCrmService())->setup(1, false);
        $this->assertCount(count(PlatformNurtureRecipes::STAGES), $a['stages']);
        $this->assertArrayHasKey('subscriber', $a['tags']);
        $this->assertCount(3, $a['flows']);
        $b = (new PlatformCrmService())->setup(1, false);
        $this->assertSame($a['pipeline_id'], $b['pipeline_id']);
        $this->assertSame(1, (int) db_connect()->table('pipelines')->where('tenant_id', self::MK)->where('is_default', 1)->countAllResults());
        $this->assertSame(3, (int) db_connect()->table('flows')->where('tenant_id', self::MK)->countAllResults());
        $this->assertSame('draft', db_connect()->table('flows')->where('tenant_id', self::MK)->get()->getRowArray()['status']);
        $this->assertFalse(in_array(true, array_column($b['flows'], 'created'), true));
    }

    public function testGraphsAreWellFormedAndGuardedByTheSignedUpCheck(): void
    {
        (new PlatformCrmService())->setup(1, false);
        foreach (db_connect()->table('flows')->where('tenant_id', self::MK)->get()->getResultArray() as $f) {
            $g = json_decode($f['graph'], true);
            $ids = array_column($g['nodes'], 'id');
            $this->assertSame(count($ids), count(array_unique($ids)), $f['name']);
            foreach ($g['edges'] as $e) { $this->assertContains($e['source'], $ids); $this->assertContains($e['target'], $ids); }
            if ($f['trigger_type'] === 'meta_lead_received') {
                $this->assertContains('condition', array_column($g['nodes'], 'type'));
                $this->assertContains('create_deal', array_column($g['nodes'], 'type'));
                $this->assertContains('send_email', array_column($g['nodes'], 'type'));
                $this->assertContains('send_template', array_column($g['nodes'], 'type'));
            }
        }
    }

    public function testAutomationCannotGoLiveUntilWhatsappTemplatesAreApproved(): void
    {
        (new PlatformCrmService())->setup(1, false);
        $this->expectException(\DomainException::class);
        (new PlatformCrmService())->activate('fb_welcome_nurture', true, 1);
    }

    public function testActivationWorksOnceTemplatesAreApproved(): void
    {
        $svc = new PlatformCrmService(); $svc->setup(1, false);
        db_connect()->table('templates')->where('tenant_id', self::MK)->update(['meta_status' => 'approved']);
        $this->assertSame('active', $svc->activate('fb_welcome_nurture', true, 1)['status']);
        $this->assertSame('paused', $svc->activate('fb_welcome_nurture', false, 1)['status']);
    }

    public function testReconcileMovesLeadsToSignedUpSubscribedAndEngaged(): void
    {
        $svc = new PlatformCrmService(); $svc->setup(1, false);
        $free = $this->lead('Asha', 'ASHA@alpha.test', null);                                  // has a free account (e-mail match, case-insensitive)
        $paid = $this->lead('Bala', null, '+91 98765-00002');                                  // paying account (phone match)
        $reply = $this->lead('Chitra', 'c@x.test', '9811111111', ['last_inbound_at' => date('Y-m-d H:i:s', time() - 600)]);
        $cold = $this->lead('Dev', 'd@x.test', '9822222222');
        $r = $svc->reconcile();
        $this->assertSame(4, $r['checked']);
        $this->assertSame('Signed up (free)', $this->stageOf($free['deal']));
        $this->assertSame('Subscribed', $this->stageOf($paid['deal']));
        $this->assertSame('Engaged', $this->stageOf($reply['deal']));
        $this->assertSame('New lead', $this->stageOf($cold['deal']));
        $this->assertSame('won', db_connect()->table('deals')->where('id', $paid['deal'])->get()->getRowArray()['status']);
        $tag = fn (int $c, string $n) => (int) db_connect()->query('SELECT COUNT(*) n FROM contact_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.contact_id = ? AND t.name = ?', [$c, $n])->getRow()->n;
        $this->assertSame(1, $tag($free['contact'], 'Signed up'));
        $this->assertSame(0, $tag($free['contact'], 'Subscriber'));
        $this->assertSame(1, $tag($paid['contact'], 'Subscriber'));
        $this->assertSame(1, $tag($reply['contact'], 'Hot lead'));
        $again = $svc->reconcile();                                                           // idempotent: only the free lead and the cold one are still open
        $this->assertSame(0, $again['signed_up'] + $again['subscribed'] + $again['engaged']);
    }

    public function testReconcileIgnoresTheMarketingWorkspacesOwnAccountAndNeverMovesBackwards(): void
    {
        $svc = new PlatformCrmService(); $svc->setup(1, false);
        $l = $this->lead('Internal', 'mk@tripsarthi.test', null);
        $svc->reconcile();
        $this->assertSame('New lead', $this->stageOf($l['deal']));
        $reply = $this->lead('Eve', 'e@x.test', '9833333333', ['last_inbound_at' => date('Y-m-d H:i:s')]);
        $svc->reconcile();
        $this->assertSame('Engaged', $this->stageOf($reply['deal']));
        db_connect()->table('contacts')->where('id', $reply['contact'])->update(['last_inbound_at' => null]);
        $svc->reconcile();
        $this->assertSame('Engaged', $this->stageOf($reply['deal']));
    }

    public function testDashboardCountsFunnelAndConversion(): void
    {
        $svc = new PlatformCrmService(); $svc->setup(1, false);
        $this->lead('Asha', 'asha@alpha.test', null); $this->lead('Bala', 'bala@beta.test', null); $this->lead('Cold', 'c@x.test', '9844444444');
        $svc->reconcile();
        $d = $svc->dashboard(30);
        $this->assertSame(3, $d['kpis']['new_leads']);
        $this->assertSame(3, $d['kpis']['facebook_leads']);
        $this->assertSame(2, $d['kpis']['signed_up']);
        $this->assertSame(1, $d['kpis']['subscribed']);
        $this->assertEquals(33.3, $d['kpis']['conversion_pct']);
        $byStage = array_column($d['funnel'], 'count', 'stage');
        $this->assertSame(1, $byStage['Subscribed']); $this->assertSame(1, $byStage['Signed up (free)']); $this->assertSame(1, $byStage['New lead']);
        $this->assertSame(1, $d['attention']['untouched']);
        $this->assertSame(30, $svc->dashboard(5)['days']);
    }

    public function testNoMarketingWorkspaceIsExplained(): void
    {
        PlatformSettings::set(PlatformSettings::MARKETING_TENANT, '0');
        $this->assertNull((new PlatformCrmService())->status()['workspace']);
        $this->expectException(\DomainException::class);
        (new PlatformCrmService())->setup(1, false);
    }
}
