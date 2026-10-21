<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ConversationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Contacts store "91…", conversations store "+91…": a read-only lookup must find the chat either way, and never across workspaces. MySQL group. */
#[Group('mysql')]
final class ConversationLookupTest extends CIUnitTestCase
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
        foreach (['conversations', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('conversations')->insert(['tenant_id' => 1, 'wa_number' => '+919877712345', 'status' => 'open', 'window_expires_at' => '2030-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00']);
    }

    public function testFindsTheChatWhetherOrNotThePlusWasStored(): void
    {
        $m = new ConversationModel();
        foreach (['+919877712345', '919877712345'] as $n) {
            $row = $m->findByNumber(1, $n);
            $this->assertNotNull($row, $n);
            $this->assertSame('+919877712345', $row['wa_number']);
        }
        $this->assertNull($m->findByNumber(1, '919000000000'), 'an unknown number is not found (and nothing is created)');
        $this->assertSame(1, db_connect()->table('conversations')->countAllResults());
    }

    public function testAnotherWorkspaceNeverSeesIt(): void
    {
        $this->assertNull((new ConversationModel())->findByNumber(2, '919877712345'));
    }
}
