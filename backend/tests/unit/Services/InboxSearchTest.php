<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MessageModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** MySQL-backed (group "mysql"): run with scripts/test-mysql.sh */
#[Group('mysql')]
final class InboxSearchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['messages', 'conversations', 'contacts', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) {
            $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
            $db->table('contacts')->insert(['tenant_id' => $t, 'name' => "C$t", 'wa_number' => "9198765000$t", 'created_at' => '2026-01-01 00:00:00']);
            $cid = (int) $db->insertID();
            $db->table('conversations')->insert(['tenant_id' => $t, 'contact_id' => $cid, 'wa_number' => "9198765000$t", 'contact_name' => "Customer $t", 'status' => 'open', 'created_at' => '2026-01-01 00:00:00']);
            $conv = (int) $db->insertID();
            foreach (['Please share the Bali visa checklist', '100% advance is fine', 'Thanks a lot'] as $i => $body) {
                $db->table('messages')->insert(['tenant_id' => $t, 'contact_id' => $cid, 'conversation_id' => $conv, 'direction' => $i === 2 ? 'out' : 'in', 'type' => 'text', 'category' => 'free_form', 'body' => $body, 'status' => 'delivered', 'created_at' => '2026-10-0' . ($i + 1) . ' 10:00:00']);
            }
        }
    }

    public function testFindsTextInThisWorkspaceOnly(): void
    {
        $r = MessageModel::searchText(1, 'visa');
        $this->assertCount(1, $r);
        $this->assertSame('Customer 1', $r[0]['contact_name']);
        $this->assertStringContainsString('visa', $r[0]['snippet']);
        $this->assertArrayNotHasKey('body', $r[0]);
        $this->assertSame([], array_filter(MessageModel::searchText(2, 'visa'), static fn ($x) => $x['contact_name'] === 'Customer 1'), 'workspace 2 must never see workspace 1 messages');
    }

    public function testNeedsThreeCharactersAndTreatsWildcardsAsText(): void
    {
        $this->assertSame([], MessageModel::searchText(1, 'vi'));
        $this->assertSame([], MessageModel::searchText(1, '  '));
        $this->assertCount(1, MessageModel::searchText(1, '100%'), 'a typed % matches a literal %');
        $this->assertSame([], MessageModel::searchText(1, '%%%'), 'wildcards must not match everything');
        $this->assertSame([], MessageModel::searchText(1, "x' OR '1'='1"));
    }
}
