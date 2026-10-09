<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Models\PipelineModel;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Seeds demo deals across the default pipeline for tenant 1 so the kanban board
 * is populated. Idempotent: skips if deals already exist.
 */
class DealDemoSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->db->table('deals')->where('tenant_id', 1)->countAllResults() > 0) {
            CLI::write('DealDemoSeeder: deals already present, skipping.', 'yellow');
            return;
        }

        $pipeline = (new PipelineModel())->ensureDefault(1);
        $stages   = $this->db->table('pipeline_stages')->where('tenant_id', 1)
            ->where('pipeline_id', $pipeline['id'])->orderBy('position', 'ASC')->get()->getResultArray();
        if (empty($stages)) {
            CLI::write('DealDemoSeeder: no stages, skipping.', 'yellow');
            return;
        }
        $stageByName = [];
        foreach ($stages as $s) {
            $stageByName[$s['name']] = (int) $s['id'];
        }

        $accounts = $this->db->table('accounts')->where('tenant_id', 1)->get()->getResultArray();
        $accId = static fn (int $i) => $accounts[$i]['id'] ?? null;

        $now = date('Y-m-d H:i:s');
        $deals = [
            // [title, stage, value(paise), account index]
            ['Nimbus annual contract',   'Negotiation', 25000000, 0],
            ['Orbit fleet rollout',      'Proposal',    18000000, 1],
            ['Vertex platform licence',  'Qualified',   12000000, 2],
            ['Nimbus add-on stores',     'Lead',         6000000, 0],
            ['Orbit pilot programme',    'Lead',         3500000, 1],
        ];

        foreach ($deals as $i => [$title, $stage, $value, $aIdx]) {
            $this->db->table('deals')->insert([
                'tenant_id'        => 1,
                'title'            => $title,
                'pipeline_id'      => (int) $pipeline['id'],
                'stage_id'         => $stageByName[$stage] ?? (int) $stages[0]['id'],
                'account_id'       => $accId($aIdx),
                'owner_id'         => 1,
                'value_amount'     => $value,
                'currency'         => 'INR',
                'status'           => 'open',
                'expected_close_date' => date('Y-m-d', strtotime('+' . (($i + 1) * 10) . ' days')),
                'last_activity_at' => $now,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        CLI::write('DealDemoSeeder: 5 demo deals seeded across the pipeline.', 'green');
    }
}
