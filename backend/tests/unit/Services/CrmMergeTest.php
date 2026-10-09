<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Models\DealModel;
use App\Models\TicketModel;
use App\Services\Crm\DedupeMergeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase G5: duplicate detection + merge. Verifies re-pointing of CRM
 * references, field-fill, loser soft-delete, tenant safety, and the WhatsApp
 * read-only guardrail (messaging is never re-pointed).
 */
class CrmMergeTest extends CIUnitTestCase
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

    private function contact(int $tenant, array $f): int
    {
        return (int) (new ContactModel())->setTenant($tenant)
            ->insert(array_merge(['status' => 'new', 'source' => 'manual'], $f), true);
    }

    public function testFindDuplicates(): void
    {
        $this->contact(1, ['wa_number' => '+9401', 'name' => 'A', 'email' => 'dup@x.com']);
        $this->contact(1, ['wa_number' => '+9402', 'name' => 'B', 'email' => 'dup@x.com']);
        $this->contact(1, ['wa_number' => '+9403', 'name' => 'C', 'email' => 'unique@x.com']);

        $groups = (new DedupeMergeService())->findDuplicates('contact', 1);
        $this->assertCount(1, $groups);
        $this->assertSame('email', $groups[0]['field']);
        $this->assertCount(2, $groups[0]['records']);
    }

    public function testMergeRepointsAndSoftDeletes(): void
    {
        $primary = $this->contact(1, ['wa_number' => '+9411', 'name' => 'Primary', 'email' => 'm@x.com']);
        $loser   = $this->contact(1, ['wa_number' => '+9412', 'name' => 'Loser', 'email' => 'm@x.com']);

        $dealId   = (int) (new DealModel())->setTenant(1)->insert(['title' => 'D', 'pipeline_id' => 1, 'stage_id' => 1, 'primary_contact_id' => $loser], true);
        $ticketId = (int) (new TicketModel())->setTenant(1)->insert(['subject' => 'T', 'contact_id' => $loser], true);
        $db = db_connect();
        $db->table('notes')->insert(['tenant_id' => 1, 'body' => 'n', 'related_type' => 'contact', 'related_id' => $loser]);
        $db->table('associations')->insert(['tenant_id' => 1, 'from_type' => 'contact', 'from_id' => $loser, 'to_type' => 'deal', 'to_id' => 99]);

        $res = (new DedupeMergeService())->merge('contact', 1, $primary, [$loser]);
        $this->assertSame(1, $res['merged']);

        $this->assertSame($primary, (int) (new DealModel())->setTenant(1)->find($dealId)['primary_contact_id']);
        $this->assertSame($primary, (int) (new TicketModel())->setTenant(1)->find($ticketId)['contact_id']);
        $this->assertSame($primary, (int) $db->table('notes')->where('related_type', 'contact')->get()->getRow()->related_id);
        $this->assertSame($primary, (int) $db->table('associations')->get()->getRow()->from_id);

        // Loser soft-deleted, primary alive.
        $this->assertNull((new ContactModel())->setTenant(1)->find($loser));
        $this->assertNotNull((new ContactModel())->setTenant(1)->onlyDeleted()->find($loser));
        $this->assertNotNull((new ContactModel())->setTenant(1)->find($primary));
    }

    public function testMergeFillsEmptyPrimaryFields(): void
    {
        $primary = $this->contact(1, ['wa_number' => '+9421', 'name' => '', 'email' => 'f@x.com']);
        $loser   = $this->contact(1, ['wa_number' => '+9422', 'name' => 'Has Name', 'email' => 'f@x.com']);

        (new DedupeMergeService())->merge('contact', 1, $primary, [$loser]);
        $this->assertSame('Has Name', (new ContactModel())->setTenant(1)->find($primary)['name']);
    }

    public function testMergeCannotCrossTenant(): void
    {
        $primary = $this->contact(1, ['wa_number' => '+9431', 'name' => 'T1', 'email' => 'a@x.com']);
        $foreign = $this->contact(2, ['wa_number' => '+9432', 'name' => 'T2', 'email' => 'a@x.com']);

        $res = (new DedupeMergeService())->merge('contact', 1, $primary, [$foreign]);
        $this->assertSame(0, $res['merged'], 'foreign loser is not resolvable');
        $this->assertNotNull((new ContactModel())->setTenant(2)->find($foreign), 'foreign row untouched');
    }

    public function testMergeLeavesMessagingUntouched(): void
    {
        $primary = $this->contact(1, ['wa_number' => '+9441', 'name' => 'P', 'email' => 'g@x.com']);
        $loser   = $this->contact(1, ['wa_number' => '+9442', 'name' => 'L', 'email' => 'g@x.com']);

        $db = db_connect();
        $db->table('messages')->insert(['tenant_id' => 1, 'contact_id' => $loser, 'conversation_id' => 1, 'direction' => 'in', 'body' => 'hi']);

        (new DedupeMergeService())->merge('contact', 1, $primary, [$loser]);

        // Guardrail: the message still points at the original (loser) contact.
        $msg = $db->table('messages')->get()->getRow();
        $this->assertSame($loser, (int) $msg->contact_id, 'messaging is read-only — not re-pointed');
    }

    public function testTagCollisionDeduped(): void
    {
        $primary = $this->contact(1, ['wa_number' => '+9451', 'name' => 'P', 'email' => 'h@x.com']);
        $loser   = $this->contact(1, ['wa_number' => '+9452', 'name' => 'L', 'email' => 'h@x.com']);

        $db = db_connect();
        // Both share tag 10; loser also has tag 20.
        $db->table('contact_tags')->insert(['contact_id' => $primary, 'tag_id' => 10]);
        $db->table('contact_tags')->insert(['contact_id' => $loser, 'tag_id' => 10]);
        $db->table('contact_tags')->insert(['contact_id' => $loser, 'tag_id' => 20]);

        (new DedupeMergeService())->merge('contact', 1, $primary, [$loser]);

        $tags = array_column($db->table('contact_tags')->where('contact_id', $primary)->get()->getResultArray(), 'tag_id');
        sort($tags);
        $this->assertSame([10, 20], array_map('intval', $tags), 'tag 10 not duplicated, tag 20 moved over');
    }
}
