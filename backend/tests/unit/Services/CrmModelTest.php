<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AccountModel;
use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\NoteModel;
use App\Models\TaskModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase A model behaviour: tenant isolation, account↔contact linkage,
 * the activity log, note ordering, and task lifecycle.
 */
class CrmModelTest extends CIUnitTestCase
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

    /** ContactModel requires status+source (in_list); the app always sets them. */
    private function contact(array $fields): array
    {
        return array_merge(['status' => 'new', 'source' => 'manual'], $fields);
    }

    // ── Tenant isolation ───────────────────────────────────────────────

    public function testAccountsAreTenantScoped(): void
    {
        (new AccountModel())->setTenant(1)->insert(['name' => 'Acme T1']);
        (new AccountModel())->setTenant(2)->insert(['name' => 'Globex T2']);

        $t1 = (new AccountModel())->setTenant(1)->findAll();
        $this->assertCount(1, $t1);
        $this->assertSame('Acme T1', $t1[0]['name']);

        $t2 = (new AccountModel())->setTenant(2)->findAll();
        $this->assertCount(1, $t2);
        $this->assertSame('Globex T2', $t2[0]['name']);
    }

    public function testBaseModelThrowsWithoutTenant(): void
    {
        $this->expectException(\RuntimeException::class);
        (new TaskModel())->findAll(); // no setTenant() → fail-closed
    }

    // ── Account ↔ contact linkage ──────────────────────────────────────

    public function testAccountContactCount(): void
    {
        $accId = (int) (new AccountModel())->setTenant(1)->insert(['name' => 'Acme'], true);

        $cm = new ContactModel();
        $cm->setTenant(1)->insert($this->contact(['wa_number' => '+919900000001', 'name' => 'A', 'account_id' => $accId]));
        $cm->setTenant(1)->insert($this->contact(['wa_number' => '+919900000002', 'name' => 'B', 'account_id' => $accId]));
        $cm->setTenant(1)->insert($this->contact(['wa_number' => '+919900000003', 'name' => 'C'])); // unlinked

        $this->assertSame(2, (new AccountModel())->contactCount(1, $accId));
    }

    public function testContactAcceptsCrmFields(): void
    {
        $id = (int) (new ContactModel())->setTenant(1)->insert($this->contact([
            'wa_number'       => '+919900000010',
            'lifecycle_stage' => 'sql',
            'job_title'       => 'Head of Ops',
            'lead_score'      => 42,
        ]), true);

        $c = (new ContactModel())->setTenant(1)->find($id);
        $this->assertSame('sql', $c['lifecycle_stage']);
        $this->assertSame('Head of Ops', $c['job_title']);
        $this->assertSame(42, (int) $c['lead_score']);
    }

    public function testContactRejectsInvalidLifecycle(): void
    {
        $ok = (new ContactModel())->setTenant(1)->insert($this->contact([
            'wa_number'       => '+919900000011',
            'lifecycle_stage' => 'bogus_stage',
        ]));
        $this->assertFalse($ok, 'Invalid lifecycle_stage must fail validation');
    }

    // ── Activity log ───────────────────────────────────────────────────

    public function testActivityLogAndForRecordNewestFirst(): void
    {
        $am = new ActivityModel();
        $am->log(1, 'note', 'contact', 7, ['body' => 'first',  'occurred_at' => '2026-06-10 09:00:00']);
        $am->log(1, 'call', 'contact', 7, ['body' => 'second', 'occurred_at' => '2026-06-11 09:00:00']);
        $am->log(1, 'note', 'contact', 99, ['body' => 'other record']);

        $feed = (new ActivityModel())->forRecord(1, 'contact', 7);
        $this->assertCount(2, $feed, 'Only this record’s activities');
        $this->assertSame('second', $feed[0]['body'], 'Newest first');
        $this->assertSame('first',  $feed[1]['body']);
    }

    public function testActivityMetaIsJsonEncoded(): void
    {
        (new ActivityModel())->log(1, 'stage_change', 'deal', 3, ['meta' => ['from' => 'a', 'to' => 'b']]);
        $row  = (new ActivityModel())->forRecord(1, 'deal', 3)[0];
        $meta = json_decode($row['meta'], true);
        $this->assertSame('b', $meta['to']);
    }

    // ── Notes ──────────────────────────────────────────────────────────

    public function testNotesPinnedFirst(): void
    {
        $nm = new NoteModel();
        $nm->setTenant(1)->insert(['body' => 'plain',  'related_type' => 'contact', 'related_id' => 5, 'is_pinned' => 0]);
        $nm->setTenant(1)->insert(['body' => 'pinned', 'related_type' => 'contact', 'related_id' => 5, 'is_pinned' => 1]);

        $notes = (new NoteModel())->forRecord(1, 'contact', 5);
        $this->assertSame('pinned', $notes[0]['body'], 'Pinned note sorts first');
    }

    // ── Tasks ──────────────────────────────────────────────────────────

    public function testTaskMarkDoneSetsCompletedAt(): void
    {
        $id = (int) (new TaskModel())->setTenant(1)->insert(['title' => 'Call back', 'assigned_user_id' => 9], true);

        $this->assertTrue((new TaskModel())->markDone(1, $id));

        $t = (new TaskModel())->setTenant(1)->find($id);
        $this->assertSame('done', $t['status']);
        $this->assertNotNull($t['completed_at']);
    }

    public function testOpenForUserExcludesDoneAndOtherUsers(): void
    {
        $tm = new TaskModel();
        $tm->setTenant(1)->insert(['title' => 'Mine open',  'assigned_user_id' => 9, 'status' => 'open']);
        $tm->setTenant(1)->insert(['title' => 'Mine done',  'assigned_user_id' => 9, 'status' => 'done']);
        $tm->setTenant(1)->insert(['title' => 'Theirs',     'assigned_user_id' => 8, 'status' => 'open']);

        $mine = (new TaskModel())->openForUser(1, 9);
        $this->assertCount(1, $mine);
        $this->assertSame('Mine open', $mine[0]['title']);
    }

    public function testTasksAreTenantScoped(): void
    {
        (new TaskModel())->setTenant(1)->insert(['title' => 'T1 task', 'related_type' => 'contact', 'related_id' => 1]);
        (new TaskModel())->setTenant(2)->insert(['title' => 'T2 task', 'related_type' => 'contact', 'related_id' => 1]);

        $t1 = (new TaskModel())->forRecord(1, 'contact', 1);
        $this->assertCount(1, $t1);
        $this->assertSame('T1 task', $t1[0]['title']);
    }
}
