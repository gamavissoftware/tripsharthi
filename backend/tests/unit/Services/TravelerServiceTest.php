<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\ChecklistService;
use App\Services\Travel\TravelerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class TravelerServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    private int $booking; private int $tr;

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['booking_checklist_items', 'travelers', 'bookings', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('bookings')->insert(['tenant_id' => 1, 'booking_ref' => 'TP-1', 'title' => 'Bali', 'status' => 'confirmed', 'is_international' => 1, 'travel_start' => '2026-12-01', 'subtotal' => 1, 'cost_total' => 0, 'total_amount' => 1, 'paid_amount' => 0, 'created_at' => '2026-09-01 00:00:00']);
        $this->booking = (int) $db->insertID();
        $db->table('travelers')->insert(['tenant_id' => 1, 'booking_id' => $this->booking, 'full_name' => 'Rohit', 'pax_type' => 'adult', 'created_at' => '2026-09-01 00:00:00']);
        $this->tr = (int) $db->insertID();
        (new ChecklistService())->generate(1, $this->booking);
    }

    public function testUpdateValidatesAndEncryptsThePassportNumber(): void
    {
        $r = (new TravelerService())->update(1, $this->tr, ['full_name' => ' Rohit Sharma ', 'passport_no' => 'p 1234567', 'passport_expiry' => '2031-05-01', 'visa_status' => 'applied', 'nationality' => 'Indian'], 5);
        $this->assertSame('Rohit Sharma', $r['full_name']);
        $this->assertTrue($r['has_passport']);
        $this->assertArrayNotHasKey('passport_no_enc', $r);
        $raw = db_connect()->table('travelers')->where('id', $this->tr)->get()->getRowArray();
        $this->assertStringNotContainsString('P1234567', (string) $raw['passport_no_enc'], 'the number must not be stored in clear');
        $this->assertSame('P1234567', (new TravelerService())->passport(1, $this->tr, 5));
        $log = db_connect()->table('audit_logs')->where('action', 'traveler.passport_view')->countAllResults();
        $this->assertSame(1, $log, 'showing a passport number must be audited');
        // a blank passport_no keeps the saved one
        (new TravelerService())->update(1, $this->tr, ['passport_no' => '  ', 'meal_pref' => 'veg']);
        $this->assertSame('P1234567', (new TravelerService())->passport(1, $this->tr));
        foreach ([['visa_status' => 'bitcoin'], ['pax_type' => 'senior'], ['dob' => '31-02-1990'], ['passport_expiry' => 'soon'], ['passport_no' => '12'], ['full_name' => ' ']] as $bad) {
            try { (new TravelerService())->update(1, $this->tr, $bad); $this->fail('accepted ' . json_encode($bad)); } catch (\InvalidArgumentException $e) { $this->assertTrue(true); }
        }
    }

    public function testAnotherWorkspaceCannotTouchOrReadATraveller(): void
    {
        foreach ([fn () => (new TravelerService())->update(2, $this->tr, ['full_name' => 'X']), fn () => (new TravelerService())->passport(2, $this->tr), fn () => (new TravelerService())->delete(2, $this->tr)] as $f) {
            try { $f(); $this->fail('cross-workspace access'); } catch (\OutOfBoundsException $e) { $this->assertTrue(true); }
        }
    }

    public function testPassportRefusedWhenNoneSavedAndDeleteProtectsDocumentsOnFile(): void
    {
        try { (new TravelerService())->passport(1, $this->tr); $this->fail('no number saved'); } catch (\DomainException $e) { $this->assertTrue(true); }
        $item = db_connect()->table('booking_checklist_items')->where('traveler_id', $this->tr)->get()->getRowArray();
        $this->assertNotNull($item, 'checklist exists for the traveller');
        db_connect()->table('booking_checklist_items')->where('id', $item['id'])->update(['status' => 'received']);
        try { (new TravelerService())->delete(1, $this->tr); $this->fail('deleted a traveller with documents on file'); } catch (\DomainException $e) { $this->assertTrue(true); }
        db_connect()->table('booking_checklist_items')->where('id', $item['id'])->update(['status' => 'pending']);
        (new TravelerService())->delete(1, $this->tr);
        $this->assertSame(0, db_connect()->table('booking_checklist_items')->where('traveler_id', $this->tr)->countAllResults());
        $this->assertSame(0, db_connect()->table('travelers')->where('id', $this->tr)->where('deleted_at', null)->countAllResults());
    }
}
