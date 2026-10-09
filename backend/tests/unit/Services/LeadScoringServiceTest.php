<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Models\DealModel;
use App\Models\LeadScoringRuleModel;
use App\Services\Crm\LeadScoringService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase H1: lead-score math, tier thresholds, custom weights, recency decay,
 * read-only engagement signal, and persistence.
 */
class LeadScoringServiceTest extends CIUnitTestCase
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

    private const NOW = 1_700_000_000; // fixed clock for deterministic decay

    public function testTiersFromSignals(): void
    {
        $svc = new LeadScoringService();

        // Cold: bare manual lead.
        $cold = $this->contact(1, ['wa_number' => '+9501', 'lifecycle_stage' => 'lead', 'updated_at' => date('Y-m-d H:i:s', self::NOW)]);
        $r    = $svc->score((new ContactModel())->setTenant(1)->find($cold), 1, self::NOW);
        $this->assertSame('cold', $r['tier']);
        $this->assertSame(10 + 5, $r['score']); // lifecycle lead + manual source

        // Hot: opportunity + good source + email + account.
        $hot = $this->contact(1, ['wa_number' => '+9502', 'lifecycle_stage' => 'opportunity', 'source' => 'meta_lead_ads', 'email' => 'a@b.com', 'account_id' => 7, 'updated_at' => date('Y-m-d H:i:s', self::NOW)]);
        $r   = $svc->score((new ContactModel())->setTenant(1)->find($hot), 1, self::NOW);
        $this->assertSame(60 + 15 + 5 + 5, $r['score']);
        $this->assertSame('hot', $r['tier']);
    }

    public function testEngagementCountsInboundMessagesReadOnly(): void
    {
        $id = $this->contact(1, ['wa_number' => '+9511', 'lifecycle_stage' => 'lead', 'updated_at' => date('Y-m-d H:i:s', self::NOW)]);
        $db = db_connect();
        foreach (range(1, 3) as $i) {
            $db->table('messages')->insert(['tenant_id' => 1, 'contact_id' => $id, 'conversation_id' => 1, 'direction' => 'in', 'body' => "m{$i}"]);
        }
        $db->table('messages')->insert(['tenant_id' => 1, 'contact_id' => $id, 'conversation_id' => 1, 'direction' => 'out', 'body' => 'reply']);

        $before = $db->table('messages')->countAllResults();
        $r = (new LeadScoringService())->score((new ContactModel())->setTenant(1)->find($id), 1, self::NOW);
        // 3 inbound × 2 = 6 engagement points (outbound ignored).
        $this->assertSame(6, $r['breakdown']['engagement']);
        $this->assertSame($before, $db->table('messages')->countAllResults(), 'scoring never writes messages');
    }

    public function testRecencyDecayReducesScore(): void
    {
        $stale = $this->contact(1, ['wa_number' => '+9521', 'lifecycle_stage' => 'sql', 'last_inbound_at' => date('Y-m-d H:i:s', self::NOW - 28 * 86400)]);
        $r = (new LeadScoringService())->score((new ContactModel())->setTenant(1)->find($stale), 1, self::NOW);
        // 4 weeks × 3 = 12 decay points off (sql 40 + manual 5) → 33.
        $this->assertSame(-12, $r['breakdown']['decay']);
        $this->assertSame(33, $r['score']);
    }

    public function testCustomWeightsAndThresholds(): void
    {
        (new LeadScoringRuleModel())->setTenant(1)->insert([
            'tenant_id'      => 1,
            'weights'        => json_encode(['lifecycle' => ['lead' => 90]]),
            'hot_threshold'  => 50,
            'warm_threshold' => 20,
        ]);
        $id = $this->contact(1, ['wa_number' => '+9531', 'lifecycle_stage' => 'lead', 'updated_at' => date('Y-m-d H:i:s', self::NOW)]);
        $r  = (new LeadScoringService())->score((new ContactModel())->setTenant(1)->find($id), 1, self::NOW);
        $this->assertSame(90 + 5, $r['score']); // overridden lead weight + manual source
        $this->assertSame('hot', $r['tier']);   // ≥ custom hot threshold 50
    }

    public function testRecalcPersistsToContact(): void
    {
        $id = $this->contact(1, ['wa_number' => '+9541', 'lifecycle_stage' => 'mql', 'updated_at' => date('Y-m-d H:i:s', self::NOW)]);
        (new LeadScoringService())->recalc($id, 1, self::NOW);
        $row = (new ContactModel())->setTenant(1)->find($id);
        $this->assertSame(30, (int) $row['lead_score']); // mql 25 + manual 5
        $this->assertSame('cold', $row['score_tier']);
        $this->assertSame(25, json_decode($row['score_breakdown'], true)['lifecycle']);
    }
}
