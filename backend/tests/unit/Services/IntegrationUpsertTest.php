<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\IntegrationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\BlankSlateSchema;

/**
 * Regression: saving an integration (AI key, Meta page, …) must UPSERT and
 * restore a soft-deleted row, not insert a duplicate. The UNIQUE(tenant_id,type)
 * constraint counts soft-deleted rows, so a naive insert threw
 * "Duplicate entry '1-ai_assistant'" — which broke saving the Anthropic key
 * (and reconnecting a Meta page).
 */
class IntegrationUpsertTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use BlankSlateSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}integrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, type TEXT NOT NULL,
            page_id TEXT, verify_token TEXT, config TEXT,
            status TEXT DEFAULT 'active',
            created_at TEXT, updated_at TEXT, deleted_at TEXT,
            UNIQUE(tenant_id, type)
        )");
        $db->query("DELETE FROM {$p}integrations WHERE tenant_id = 9");
    }

    public function testSaveConfigRestoresSoftDeletedRowWithoutDuplicate(): void
    {
        $model = new IntegrationModel();

        $id1 = $model->saveConfig(9, 'ai_assistant', ['config' => json_encode(['k' => 1])]);
        $this->assertGreaterThan(0, $id1);

        // Soft-delete it (simulates a prior disconnect).
        db_connect()->table('integrations')->where('id', $id1)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        // Saving again must restore the SAME row, not throw a duplicate-key error.
        $id2 = $model->saveConfig(9, 'ai_assistant', ['config' => json_encode(['k' => 2])]);

        $this->assertSame($id1, $id2, 'must reuse the existing row');

        $row = db_connect()->table('integrations')->where('id', $id1)->get()->getRowArray();
        $this->assertNull($row['deleted_at'], 'soft-deleted row must be restored');
        $this->assertSame('active', $row['status']);

        $count = db_connect()->table('integrations')
            ->where('tenant_id', 9)->where('type', 'ai_assistant')->countAllResults();
        $this->assertSame(1, $count, 'exactly one row — no duplicate');
    }
}
