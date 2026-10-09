<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ConversationModel;
use App\Services\Inbox\AgentRouter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CampaignTestSchema;

/**
 * Tests inbox auto-routing: rule matching, least-loaded selection, and the
 * end-to-end route() assignment.
 *
 * Reuses CampaignTestSchema for the canonical tenants/conversations tables
 * (shared :memory: DB — minimal local copies would clash with other suites)
 * and adds the users + routing_rules tables, which no other test defines.
 */
class AgentRouterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private static int $waSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCampaignSchema();
        $this->createRoutingSchema();
    }

    private function createRoutingSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT, email TEXT,
            role TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}routing_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, name TEXT,
            match_type TEXT DEFAULT 'any', match_value TEXT,
            strategy TEXT DEFAULT 'least_loaded', assigned_user_id INTEGER,
            sort_order INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("DELETE FROM {$p}users");
        $db->query("DELETE FROM {$p}routing_rules");
    }

    private function addUser(int $id): void
    {
        db_connect()->table('users')->insert(['id' => $id, 'tenant_id' => 1, 'name' => "U{$id}", 'email' => "u{$id}@x.io", 'role' => 'agent']);
    }

    private function nextWa(): string
    {
        self::$waSeq++;
        return '+9170000' . str_pad((string) self::$waSeq, 4, '0', STR_PAD_LEFT);
    }

    private function addConversation(array $o = []): int
    {
        db_connect()->table('conversations')->insert(array_merge([
            'tenant_id' => 1, 'wa_number' => $this->nextWa(), 'status' => 'open',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ], $o));
        return (int) db_connect()->insertID();
    }

    private function addRule(array $o = []): int
    {
        db_connect()->table('routing_rules')->insert(array_merge([
            'tenant_id' => 1, 'name' => 'rule', 'match_type' => 'any',
            'strategy' => 'least_loaded', 'sort_order' => 0, 'is_active' => 1,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ], $o));
        return (int) db_connect()->insertID();
    }

    // ── matches() (pure) ────────────────────────────────────────────────

    public function testMatchesAny(): void
    {
        $this->assertTrue(AgentRouter::matches(['match_type' => 'any'], []));
    }

    public function testMatchesTag(): void
    {
        $rule = ['match_type' => 'tag', 'match_value' => '5'];
        $this->assertTrue(AgentRouter::matches($rule, ['tag_ids' => [3, 5, 9]]));
        $this->assertFalse(AgentRouter::matches($rule, ['tag_ids' => [3, 9]]));
    }

    public function testMatchesKeywordCaseInsensitive(): void
    {
        $rule = ['match_type' => 'keyword', 'match_value' => 'refund'];
        $this->assertTrue(AgentRouter::matches($rule, ['body' => 'I need a REFUND please']));
        $this->assertFalse(AgentRouter::matches($rule, ['body' => 'just browsing']));
        $this->assertFalse(AgentRouter::matches(['match_type' => 'keyword', 'match_value' => ''], ['body' => 'x']));
    }

    // ── pickLeastLoaded() ───────────────────────────────────────────────

    public function testPickLeastLoadedChoosesFewestOpen(): void
    {
        $this->addUser(10); $this->addUser(20);
        // user 10 has 2 open, user 20 has 0
        $this->addConversation(['assigned_user_id' => 10]);
        $this->addConversation(['assigned_user_id' => 10]);

        $this->assertSame(20, (new AgentRouter())->pickLeastLoaded(1, [10, 20]));
    }

    public function testPickLeastLoadedTieBreaksByLowestId(): void
    {
        $this->assertSame(10, (new AgentRouter())->pickLeastLoaded(1, [20, 10]));
    }

    // ── route() ─────────────────────────────────────────────────────────

    public function testRouteAssignsViaSpecificRule(): void
    {
        $this->addUser(42);
        $convId = $this->addConversation();
        $this->addRule(['match_type' => 'any', 'strategy' => 'specific', 'assigned_user_id' => 42]);

        $assigned = (new AgentRouter())->route(1, $convId, []);
        $this->assertSame(42, $assigned);

        $conv = (new ConversationModel())->setTenant(1)->find($convId);
        $this->assertSame(42, (int) $conv['assigned_user_id']);
    }

    public function testRouteSkipsAlreadyAssignedConversation(): void
    {
        $this->addUser(42);
        $convId = $this->addConversation(['assigned_user_id' => 99]);
        $this->addRule(['match_type' => 'any', 'strategy' => 'specific', 'assigned_user_id' => 42]);

        $this->assertNull((new AgentRouter())->route(1, $convId, []));
        $conv = (new ConversationModel())->setTenant(1)->find($convId);
        $this->assertSame(99, (int) $conv['assigned_user_id'], 'Existing assignment must be preserved');
    }

    public function testRouteFirstMatchingRuleWins(): void
    {
        $this->addUser(1); $this->addUser(2);
        $convId = $this->addConversation();
        // Rule order: keyword 'vip' (specific 2) before catch-all (specific 1)
        $this->addRule(['name' => 'vip', 'match_type' => 'keyword', 'match_value' => 'vip', 'strategy' => 'specific', 'assigned_user_id' => 2, 'sort_order' => 0]);
        $this->addRule(['name' => 'all', 'match_type' => 'any', 'strategy' => 'specific', 'assigned_user_id' => 1, 'sort_order' => 1]);

        $assigned = (new AgentRouter())->route(1, $convId, ['body' => 'I am a VIP customer']);
        $this->assertSame(2, $assigned);
    }
}
