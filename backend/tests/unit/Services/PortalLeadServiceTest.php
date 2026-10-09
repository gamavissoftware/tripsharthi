<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Email\InboundEmailService;
use App\Services\Leads\PortalLeadService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class PortalLeadServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private const NOW = 1_791_439_200;   // 2026-10-08

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['lead_events', 'lead_sources', 'lead_attributions', 'trips', 'deals', 'pipeline_stages', 'pipelines', 'activities', 'emails', 'contact_tags', 'tags', 'contact_field_values', 'contacts', 'users', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('users')->insert(['id' => 9, 'tenant_id' => 1, 'name' => 'Anita', 'email' => 'a@x.test', 'password_hash' => 'x', 'role' => 'owner', 'created_at' => '2026-01-01 00:00:00']);
    }

    private function svc(?int $now = null): PortalLeadService { return new PortalLeadService($now ?? self::NOW); }
    private function source(array $o = [], int $tenant = 1): array { return $this->svc()->save($tenant, $o + ['name' => 'TravelTriangle', 'kind' => 'webhook'], null, 9); }
    private function lead(array $o = []): array { return $o + ['Customer Name' => 'Asha Rao', 'Mobile No' => '98765 43210', 'Email' => 'asha@example.com', 'Destination' => 'Bali', 'Travel Date' => '15/01/2027', 'Adults' => '2', 'Budget' => '2 lakh', 'Message' => 'Honeymoon trip please']; }

    public function testWebhookLeadCreatesContactTripAttributionAndLog(): void
    {
        $s = $this->source(['owner_id' => 9]);
        $r = $this->svc()->ingestWebhook($s['token'], $this->lead());
        $this->assertSame('created', $r['status']);
        $c = db_connect()->table('contacts')->get()->getRowArray();
        $this->assertSame(['+919876543210', 'portal', 'Asha Rao'], [$c['wa_number'], $c['source'], $c['name']]);
        $t = db_connect()->table('trips')->get()->getRowArray();
        $this->assertSame(['Bali', '2027-01-15', 2, 9, 1, 'enquiry'], [$t['destination_text'], $t['start_date'], (int) $t['adults'], (int) $t['owner_id'], (int) $t['is_international'], $t['status']]);
        $this->assertSame(20_000_000, (int) $t['budget_max']);
        $a = db_connect()->table('lead_attributions')->get()->getRowArray();
        $this->assertSame(['portal', 'TravelTriangle'], [$a['platform'], $a['channel']]);
        $e = db_connect()->table('lead_events')->get()->getRowArray();
        $this->assertSame(['created', (int) $c['id'], (int) $t['id']], [$e['status'], (int) $e['contact_id'], (int) $e['trip_id']]);
        $src = db_connect()->table('lead_sources')->get()->getRowArray();
        $this->assertSame(1, (int) $src['received_count']);
    }

    public function testRetriedDeliveryIsIgnoredButAGenuinelyNewEnquiryIsNot(): void
    {
        $s = $this->source();
        $this->svc()->ingestWebhook($s['token'], $this->lead());
        $this->assertSame('duplicate', $this->svc()->ingestWebhook($s['token'], $this->lead())['status']);
        $this->assertSame(1, db_connect()->table('trips')->countAllResults());
        // same person, a different destination a week later -> a new enquiry, not swallowed as a duplicate
        $this->assertSame('created', $this->svc(self::NOW + 8 * 86400)->ingestWebhook($s['token'], $this->lead(['Destination' => 'Kashmir', 'Travel Date' => '20/02/2027']))['status']);
        $this->assertSame(2, db_connect()->table('trips')->countAllResults());
        $this->assertSame(1, db_connect()->table('contacts')->countAllResults(), 'the person stays one contact');
    }

    public function testAnOpenEnquiryForTheSamePlaceIsNotDuplicatedAcrossDifferentMessages(): void
    {
        $s = $this->source();
        $this->svc()->ingestWebhook($s['token'], $this->lead());
        $r = $this->svc()->ingestWebhook($s['token'], $this->lead(['Message' => 'Any update on my enquiry?']));      // different text -> different hash, but same open Bali enquiry
        $this->assertSame('existing', $r['status']);
        $this->assertSame(1, db_connect()->table('trips')->countAllResults());
    }

    public function testLeadWithoutPhoneOrEmailIsRejectedWithAReason(): void
    {
        $s = $this->source();
        $r = $this->svc()->ingestWebhook($s['token'], ['Customer Name' => 'No Contact', 'Destination' => 'Goa', 'Mobile No' => '123']);
        $this->assertSame('rejected', $r['status']);
        $this->assertStringContainsString('phone', $r['reason']);
        $this->assertSame([0, 0], [db_connect()->table('contacts')->countAllResults(), db_connect()->table('trips')->countAllResults()]);
        $this->assertSame('rejected', db_connect()->table('lead_events')->get()->getRowArray()['status']);
    }

    public function testEmailOnlyLeadAndCreateTripOffStillCreateTheContact(): void
    {
        $s = $this->source(['create_trip' => false]);
        $r = $this->svc()->ingestWebhook($s['token'], ['name' => 'Mail Only', 'email' => 'mo@example.com', 'destination' => 'Goa']);
        $this->assertSame('created', $r['status']);
        $this->assertArrayNotHasKey('trip_id', $r);
        $c = db_connect()->table('contacts')->get()->getRowArray();
        $this->assertSame([null, 'mo@example.com'], [$c['wa_number'], $c['email']]);
    }

    public function testCustomFieldMapHandlesAPortalsOwnNames(): void
    {
        $s = $this->source(['field_map' => ['Guest Handle' => 'name', 'Reach Me' => 'phone']]);
        $r = $this->svc()->ingestWebhook($s['token'], ['Guest Handle' => 'Meera Iyer', 'Reach Me' => '9811122233', 'Destination' => 'Goa']);
        $this->assertSame('created', $r['status']);
        $this->assertSame(['Meera Iyer', '+919811122233'], [db_connect()->table('contacts')->get()->getRowArray()['name'], db_connect()->table('contacts')->get()->getRowArray()['wa_number']]);
    }

    public function testPortalNotificationEmailsAreRoutedAsLeadsNotAsMessagesFromThePortal(): void
    {
        $this->source(['name' => 'Justdial', 'kind' => 'email', 'email_match' => '@justdial.com']);
        $body = "Name: Rohit Verma\nMobile: 9811122233\nDestination: Kashmir\nNo of Adults: 4";
        $r = (new InboundEmailService())->ingest(1, 'Justdial Leads <leads@justdial.com>', 'crm@agency.in', 'New enquiry', $body);
        $this->assertSame('created', $r['portal_lead']);
        $emails = array_column(db_connect()->table('contacts')->select('email, wa_number')->get()->getResultArray(), 'email');
        $this->assertNotContains('leads@justdial.com', $emails, 'the portal itself must never become a contact');
        $this->assertSame('+919811122233', db_connect()->table('contacts')->get()->getRowArray()['wa_number']);
        $this->assertSame(1, db_connect()->table('trips')->countAllResults());
        // an ordinary sender is untouched by the portal rule
        $this->assertArrayNotHasKey('portal_lead', (new InboundEmailService())->ingest(1, 'Friend <friend@gmail.com>', 'crm@agency.in', 'Hello', 'Hi'));
    }

    public function testTokensAreSecretRotatableAndTenantBound(): void
    {
        $s = $this->source();
        foreach (['', 'short', str_repeat('z', 32), str_repeat('a', 32)] as $bad) {
            try { $this->svc()->ingestWebhook($bad, $this->lead()); $this->fail("accepted '$bad'"); } catch (\OutOfBoundsException) { $this->addToAssertionCount(1); }
        }
        $new = $this->svc()->rotateToken(1, $s['id'], 9);
        $this->assertNotSame($s['token'], $new['token']);
        try { $this->svc()->ingestWebhook($s['token'], $this->lead()); $this->fail('old token still works'); } catch (\OutOfBoundsException) { $this->addToAssertionCount(1); }
        $this->svc()->save(1, ['name' => 'TravelTriangle', 'enabled' => false], $s['id']);
        try { $this->svc()->ingestWebhook($new['token'], $this->lead()); $this->fail('disabled source accepted a lead'); } catch (\OutOfBoundsException) { $this->addToAssertionCount(1); }
        $this->assertSame([], $this->svc()->list(2));
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->rotateToken(2, $s['id']);
    }

    public function testSourceValidation(): void
    {
        foreach ([['name' => ''], ['name' => 'X', 'kind' => 'sms'], ['name' => 'X', 'kind' => 'email', 'email_match' => 'not an address'], ['name' => 'X', 'field_map' => ['a' => 'password']], ['name' => 'X', 'owner_id' => 12345]] as $bad) {
            try { $this->svc()->save(1, $bad); $this->fail('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame('@justdial.com', $this->svc()->save(1, ['name' => 'JD', 'kind' => 'email', 'email_match' => '  @JustDial.com '])['email_match']);
    }
}
