<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AI\AiReplyService;
use App\Services\Travel\RateCardImportService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** Preview -> choose -> commit against real MySQL tables (group "mysql"). */
#[Group('mysql')]
final class RateCardImportTest extends CIUnitTestCase
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
        foreach (['rate_import_batches', 'supplier_rates', 'suppliers', 'destinations', 'audit_logs', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        foreach ([1, 2] as $t) { $db->table('tenants')->insert(['id' => $t, 'name' => "T$t", 'slug' => "t$t", 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']); }
        $db->table('suppliers')->insert(['tenant_id' => 1, 'name' => 'Taj Resort', 'type' => 'hotel', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $db->table('suppliers')->insert(['tenant_id' => 2, 'name' => 'Other', 'type' => 'hotel', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
    }

    private function fakeAi(string $json, bool $ok = true): AiReplyService
    {
        return new class($json, $ok) extends AiReplyService {
            public function __construct(private string $j, private bool $ok) { parent::__construct(1); }
            public function withTimeout(int $ms): static { return $this; }
            public function generate(string $systemPrompt, array $history, int $maxTokens = 400, bool $forWhatsApp = true): array { return ['success' => $this->ok, 'text' => $this->j, 'error' => $this->ok ? null : 'down']; }
        };
    }

    private const CSV = "Room Type,Net Rate,Per,Valid From,Valid To\nDeluxe Room,\"12,500\",night,01/12/2026,31/12/2026\nSuite,25000,night,01/12/2026,31/12/2026\n";

    public function testCsvIsReadWithoutAiAndPreviewWritesNoRates(): void
    {
        $r = (new RateCardImportService($this->fakeAi('', false)))->preview(1, 9, 1, null, 'taj.csv', self::CSV, 'csv');
        $this->assertSame('table', $r['mode']);
        $this->assertSame(['new' => 2, 'change' => 0, 'same' => 0, 'invalid' => 0, 'duplicate_in_file' => 0, 'total' => 2], $r['counts']);
        $this->assertSame(1_250_000, $r['rows'][0]['cost_amount']);
        $this->assertSame(0, db_connect()->table('supplier_rates')->countAllResults());
    }

    public function testCommitCreatesRatesAndIsOneShot(): void
    {
        $svc = new RateCardImportService($this->fakeAi('', false));
        $p = $svc->preview(1, 9, 1, null, 'taj.csv', self::CSV, 'csv');
        $out = $svc->commit(1, 9, $p['id'], [0, 1]);
        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0], $out);
        $row = db_connect()->table('supplier_rates')->where('service_name', 'Deluxe Room')->get()->getRowArray();
        $this->assertSame([1, 'per_night', 'INR', '1250000', '2026-12-01'], [(int) $row['tenant_id'], $row['unit'], $row['currency'], (string) $row['cost_amount'], $row['valid_from']]);
        try { $svc->commit(1, 9, $p['id'], [0]); $this->fail('applied twice'); } catch (\DomainException $e) { $this->assertStringContainsString('already applied', $e->getMessage()); }
        $this->assertSame(2, db_connect()->table('supplier_rates')->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->like('action', 'rates.import')->countAllResults());
    }

    public function testReimportUpdatesInPlaceKeepingIdsAndSkipsUnchanged(): void
    {
        $svc = new RateCardImportService($this->fakeAi('', false));
        $svc->commit(1, 9, $svc->preview(1, 9, 1, null, 'a.csv', self::CSV, 'csv')['id'], [0, 1]);
        $id = (int) db_connect()->table('supplier_rates')->where('service_name', 'Deluxe Room')->get()->getRowArray()['id'];
        $p = $svc->preview(1, 9, 1, null, 'b.csv', str_replace('12,500', '13,000', self::CSV), 'csv');
        $this->assertSame(['change', 'same'], array_column($p['rows'], 'status'));
        $this->assertSame(1_250_000, $p['rows'][0]['old_amount']);
        $out = $svc->commit(1, 9, $p['id'], [0]);
        $this->assertSame(['created' => 0, 'updated' => 1], ['created' => $out['created'], 'updated' => $out['updated']]);
        $this->assertSame(2, db_connect()->table('supplier_rates')->countAllResults());
        $this->assertSame('1300000', (string) db_connect()->table('supplier_rates')->where('id', $id)->get()->getRowArray()['cost_amount']);
    }

    public function testBigPriceJumpNeedsExplicitConfirmation(): void
    {
        $svc = new RateCardImportService($this->fakeAi('', false));
        $svc->commit(1, 9, $svc->preview(1, 9, 1, null, 'a.csv', self::CSV, 'csv')['id'], [0, 1]);
        $p = $svc->preview(1, 9, 1, null, 'b.csv', str_replace('12,500', '125,000', self::CSV), 'csv');     // a dropped comma / extra zero
        $this->assertTrue($p['rows'][0]['big_change']);
        try { $svc->commit(1, 9, $p['id'], [0]); $this->fail('big change accepted silently'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('more than 50%', $e->getMessage()); }
        $this->assertSame(1_250_000, (int) db_connect()->table('supplier_rates')->where('service_name', 'Deluxe Room')->get()->getRowArray()['cost_amount']);   // rolled back, untouched
        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 1], $svc->commit(1, 9, $p['id'], [0], [], [0]));
    }

    public function testAiExtractionKeepsVerifiedPricesAndFlagsInventedOnes(): void
    {
        $text = "Our rates for 2026\nDeluxe room Rs 12,500 per night\nSuite Rs 25,000 per night\nExtra bed 3000";
        $json = json_encode(['rows' => [
            ['service_name' => 'Deluxe room', 'service_type' => 'hotel', 'unit' => 'per_night', 'amount' => 12500, 'currency' => 'INR'],
            ['service_name' => 'Suite 2 nights', 'service_type' => 'hotel', 'unit' => 'flat', 'amount' => 50000, 'currency' => 'INR'],        // computed, not printed
            ['service_name' => 'Extra bed', 'service_type' => 'hotel', 'unit' => 'per_night', 'amount' => 3000],
        ]]);
        $svc = new RateCardImportService($this->fakeAi('Here you go: ```json' . "\n" . $json . "\n```"));
        $p = $svc->preview(1, 9, 1, null, 'Pasted text', $text);
        $this->assertSame('ai', $p['mode']);
        $this->assertSame(['new', 'invalid', 'new'], array_column($p['rows'], 'status'));
        $this->assertStringContainsString('not found in your text', $p['rows'][1]['errors'][0]);
        try { $svc->commit(1, 9, $p['id'], [1]); $this->fail('invented price imported'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('not found in your text', $e->getMessage()); }
        $this->assertSame(0, db_connect()->table('supplier_rates')->countAllResults());
        // a person can correct the price (they are the authority) and then it imports
        $svc->commit(1, 9, $p['id'], [0, 1], [1 => ['amount' => 25000, 'unit' => 'per_night', 'service_name' => 'Suite']]);
        $this->assertSame(['25000' . '00', 'per_night'], [(string) db_connect()->table('supplier_rates')->where('service_name', 'Suite')->get()->getRowArray()['cost_amount'], db_connect()->table('supplier_rates')->where('service_name', 'Suite')->get()->getRowArray()['unit']]);
    }

    public function testWithoutAiPastedLinesAreStillReadAndSaidSo(): void
    {
        $p = (new RateCardImportService($this->fakeAi('', false)))->preview(1, 9, 1, null, 'Pasted text', "Deluxe Room - Rs 12,500 per night\nAirport transfer: 2,000 per vehicle");
        $this->assertSame('lines', $p['mode']);
        $this->assertSame(['per_night', 'per_vehicle'], array_column($p['rows'], 'unit'));
        $this->assertStringContainsString('AI was not available', $p['notes'][0]);
    }

    public function testForeignCurrencyIsStoredInMinorUnitsAndAnotherTenantsSupplierIsRefused(): void
    {
        $svc = new RateCardImportService($this->fakeAi('', false));
        $p = $svc->preview(1, 9, 1, null, 'usd.csv', "Service,Rate\nVilla,\"$1,250.50\"\n", 'csv');
        $this->assertSame(['USD', 125050], [$p['rows'][0]['currency'], $p['rows'][0]['cost_amount']]);
        $svc->commit(1, 9, $p['id'], [0]);
        $this->assertSame('USD', db_connect()->table('supplier_rates')->get()->getRowArray()['currency']);
        try { $svc->preview(1, 9, 2, null, 'x.csv', self::CSV, 'csv'); $this->fail('cross-tenant supplier'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('supplier', strtolower($e->getMessage())); }
        try { $svc->get(2, $p['id']); $this->fail('cross-tenant batch'); } catch (\OutOfBoundsException) { $this->addToAssertionCount(1); }
        try { $svc->commit(2, 9, $p['id'], [0]); $this->fail('cross-tenant commit'); } catch (\OutOfBoundsException) { $this->addToAssertionCount(1); }
    }

    public function testXlsxAndPdfAndEmptyInput(): void
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $sh = $ss->getActiveSheet();
        $sh->fromArray([['Rate card'], ['Service', 'Rate', 'Unit'], ['Deluxe', 12500, 'night'], ['Cab', 2000, 'vehicle']]);
        $tmp = tempnam(sys_get_temp_dir(), 'x'); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($tmp);
        $svc = new RateCardImportService($this->fakeAi('', false));
        $p = $svc->preview(1, 9, 1, null, 'r.xlsx', (string) file_get_contents($tmp), 'xlsx'); @unlink($tmp);
        $this->assertSame(['Deluxe', 'Cab'], array_column($p['rows'], 'service_name'));
        $this->assertSame([1_250_000, 200_000], array_column($p['rows'], 'cost_amount'));
        try { $svc->preview(1, 9, 1, null, 'r.pdf', '%PDF', 'pdf'); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('PDF', $e->getMessage()); }
        try { $svc->preview(1, 9, 1, null, 'e', '   '); $this->fail(); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Paste', $e->getMessage()); }
    }
}
