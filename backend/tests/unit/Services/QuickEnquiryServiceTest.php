<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\QuickEnquiryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class QuickEnquiryServiceTest extends CIUnitTestCase
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
        foreach (['trips', 'deals', 'pipeline_stages', 'pipelines', 'activities', 'contact_tags', 'tags', 'contact_field_values', 'contacts', 'users', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'T1', 'slug' => 't1', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('users')->insert(['id' => 9, 'tenant_id' => 1, 'name' => 'Anita', 'email' => 'a@x.test', 'password_hash' => 'x', 'role' => 'agent', 'created_at' => '2026-01-01 00:00:00']);
    }

    private function svc(): QuickEnquiryService { return new QuickEnquiryService(self::NOW); }

    public function testCreatesContactAndEnquiryOwnedByTheAgent(): void
    {
        $r = $this->svc()->create(1, 9, ['name' => 'Asha Rao', 'phone' => '98765 43210', 'destination' => 'Bali', 'adults' => 2, 'children' => 1, 'nights' => 6, 'start_date' => '2027-01-15', 'budget_rs' => 250000, 'notes' => 'Honeymoon']);
        $this->assertFalse($r['existing']);
        $c = db_connect()->table('contacts')->get()->getRowArray();
        $this->assertSame(['+919876543210', 'Asha Rao', 'manual'], [$c['wa_number'], $c['name'], $c['source']]);
        $t = $r['trip'];
        $this->assertSame(['Bali', '2027-01-15', 6, 9, 1, 25_000_000, 'enquiry'], [$t['destination_text'], $t['start_date'], (int) $t['nights'], (int) $t['owner_id'], (int) $t['is_international'], (int) $t['budget_max'], $t['status']]);
    }

    public function testARetriedSubmitReturnsTheSameEnquiryInsteadOfCreatingASecond(): void
    {
        $in = ['name' => 'Asha Rao', 'phone' => '9876543210', 'destination' => 'Bali'];
        $a = $this->svc()->create(1, 9, $in); $b = $this->svc()->create(1, 9, $in);
        $this->assertTrue($b['existing']);
        $this->assertSame((int) $a['trip']['id'], (int) $b['trip']['id']);
        $this->assertSame(1, db_connect()->table('trips')->countAllResults());
        $this->assertSame(1, db_connect()->table('contacts')->countAllResults());
        $this->assertFalse($this->svc()->create(1, 9, array_merge($in, ['destination' => 'Kashmir']))['existing']);   // a different place is a new enquiry
        $this->assertSame(2, db_connect()->table('trips')->countAllResults());
    }

    public function testValidationMessagesAreSpecific(): void
    {
        foreach ([['phone' => '9876543210'], ['name' => 'A', 'phone' => '123'], ['name' => 'A', 'phone' => '9876543210', 'start_date' => '2020-01-01'], ['name' => 'A', 'phone' => '9876543210', 'start_date' => 'soon'],
                  ['name' => 'A', 'phone' => '9876543210', 'nights' => 0], ['name' => 'A', 'phone' => '9876543210', 'budget_rs' => -5]] as $bad) {
            try { $this->svc()->create(1, 9, $bad); $this->fail('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(0, db_connect()->table('contacts')->countAllResults(), 'a rejected enquiry must not leave a contact behind');
    }
}
