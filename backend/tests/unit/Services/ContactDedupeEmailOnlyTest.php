<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Services\Leads\ContactDedupeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Email-only contacts (Phase M): ContactDedupeService accepts a contact with no
 * wa_number, stores NULL (not ''), and dedupes such contacts by email — while a
 * contact with a number still dedupes by number.
 */
class ContactDedupeEmailOnlyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCrmSchema();
    }

    private function svc(): ContactDedupeService
    {
        return new ContactDedupeService(new ContactModel(), new ContactFieldValueModel());
    }

    public function testEmailOnlyInsertStoresNullWaNumber(): void
    {
        $r = $this->svc()->upsert(1, ['email' => 'eo@example.com', 'name' => 'EO', 'source' => 'manual']);
        $this->assertSame('inserted', $r['action']);

        $c = db_connect()->table('contacts')->where('id', $r['contact_id'])->get()->getRowArray();
        $this->assertNull($c['wa_number']);
        $this->assertSame('eo@example.com', $c['email']);
    }

    public function testEmailOnlyDedupesByEmail(): void
    {
        $a = $this->svc()->upsert(1, ['email' => 'dup@example.com', 'name' => 'First']);
        $b = $this->svc()->upsert(1, ['email' => 'DUP@example.com', 'name' => 'Second']); // case-insensitive

        $this->assertSame('inserted', $a['action']);
        $this->assertSame('updated', $b['action']);
        $this->assertSame($a['contact_id'], $b['contact_id']);
        $this->assertSame(1, (int) db_connect()->table('contacts')->countAllResults());
    }

    public function testTwoEmailOnlyContactsDoNotCollideOnNullNumber(): void
    {
        $a = $this->svc()->upsert(1, ['email' => 'a@example.com']);
        $b = $this->svc()->upsert(1, ['email' => 'b@example.com']);
        $this->assertNotSame($a['contact_id'], $b['contact_id']);
        $this->assertSame(2, (int) db_connect()->table('contacts')->countAllResults());
    }

    public function testNumberStillDedupesByNumberNotEmail(): void
    {
        $a = $this->svc()->upsert(1, ['wa_number' => '+919990001112', 'email' => 'x@example.com']);
        // Same email but a different number → a distinct contact (number is the key).
        $b = $this->svc()->upsert(1, ['wa_number' => '+919990009998', 'email' => 'x@example.com']);
        $this->assertNotSame($a['contact_id'], $b['contact_id']);
    }

    public function testThrowsWhenNeitherProvided(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->upsert(1, ['name' => 'No identity']);
    }
}
