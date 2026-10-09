<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\DestinationModel;
use App\Models\SupplierModel;
use App\Models\SupplierRateModel;
use App\Services\AI\AiReplyService;
use App\Services\Crm\AuditLogger;

/**
 * Rate-sheet import: paste text or upload CSV / TSV / TXT / XLSX -> PREVIEW -> a person picks rows -> commit.
 *  - A real table (recognisable header with a name and a price column) is read deterministically, no AI.
 *  - Anything else goes to the model, which only EXTRACTS: every price must appear in the source text or the row is flagged (RateCardRows).
 *  - Without an AI key (or in AI_MOCK_MODE) a line reader handles "Name - Rs 12,500 per night" lists.
 * Preview writes only `rate_import_batches`. Commit updates existing rates in place (so itinerary links by rate_id stay valid) and is one-shot.
 */
final class RateCardImportService
{
    public const MAX_CHARS = 90_000; public const MAX_ROWS = 600; private const CHUNK = 6_000;

    public function __construct(private readonly ?AiReplyService $ai = null) {}

    /** @return array{id:int,mode:string,rows:list<array>,counts:array,notes:list<string>} */
    public function preview(int $tenantId, int $userId, int $supplierId, ?int $destinationId, string $sourceName, string $content, string $ext = 'txt', string $currency = 'INR'): array
    {
        $supplier = (new SupplierModel())->setTenant($tenantId)->find($supplierId) ?: throw new \InvalidArgumentException('Choose a supplier first.');
        if ($destinationId && ! (new DestinationModel())->setTenant($tenantId)->find($destinationId)) { throw new \InvalidArgumentException('Unknown destination.'); }
        $currency = strtoupper($currency ?: 'INR');
        if (! Currency::valid($currency)) { throw new \InvalidArgumentException('Unsupported default currency.'); }
        if (trim($content) === '') { throw new \InvalidArgumentException('Paste your rate list or choose a file.'); }

        [$grid, $text] = $this->read($content, strtolower($ext));
        if (mb_strlen($text) > self::MAX_CHARS) { throw new \InvalidArgumentException('That is too much text for one import (about 90,000 characters). Split it by supplier or season.'); }
        $notes = [];

        $mode = 'table'; $raws = [];
        $hdr = $grid ? RateCardRows::findHeader($grid) : null;
        if ($hdr) { $raws = RateCardRows::fromTable($grid, $hdr[0], $hdr[1]); }
        else {
            [$raws, $mode, $why] = $this->extract($text, (string) $supplier['name']);
            if ($why) { $notes[] = $why; }
        }
        if (! $raws) { throw new \InvalidArgumentException('No prices were found. Use columns like Service, Rate, Unit — or paste lines like “Deluxe room – ₹12,500 per night”.'); }
        if (count($raws) > self::MAX_ROWS) { $notes[] = 'Only the first ' . self::MAX_ROWS . ' rows were read — import the rest separately.'; $raws = array_slice($raws, 0, self::MAX_ROWS); }

        $rows = [];
        foreach ($raws as $raw) {
            $n = RateCardRows::normalise($raw, $currency, $mode === 'table' ? '' : $text, $mode === 'ai');
            $rows[] = $n['row'] + ['errors' => $n['errors'], 'warnings' => $n['warnings'], 'status' => '', 'existing_id' => null, 'old_amount' => null];
        }
        $existing = $this->existingFor($tenantId, $supplierId);
        foreach (RateCardRows::classify($rows, $existing) as $i => $st) {
            $rows[$i]['status'] = $st;
            if (($ex = $existing[RateCardRows::key($rows[$i])] ?? null) && in_array($st, ['same', 'change'], true)) {
                $rows[$i]['existing_id'] = (int) $ex['id']; $rows[$i]['old_amount'] = (int) $ex['cost_amount'];
                if ($st === 'change' && RateCardRows::bigChange((int) $ex['cost_amount'], (int) $rows[$i]['cost_amount'])) { $rows[$i]['warnings'][] = 'Price changes by more than 50% — double-check.'; $rows[$i]['big_change'] = true; }
            }
        }
        $sha = hash('sha256', $text);
        $prior = db_connect()->table('rate_import_batches')->where(['tenant_id' => $tenantId, 'source_sha' => $sha, 'status' => 'committed'])->countAllResults();
        if ($prior) { $notes[] = 'This exact text was already imported before.'; }

        $now = date('Y-m-d H:i:s'); $db = db_connect();
        $db->table('rate_import_batches')->insert(['tenant_id' => $tenantId, 'supplier_id' => $supplierId, 'destination_id' => $destinationId, 'source_name' => mb_substr($sourceName, 0, 255), 'source_sha' => $sha,
            'mode' => $mode, 'rows' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'status' => 'previewed', 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        return ['id' => (int) $db->insertID(), 'mode' => $mode, 'rows' => $rows, 'counts' => self::counts($rows), 'notes' => $notes];
    }

    public function get(int $tenantId, int $id): array
    {
        $b = db_connect()->table('rate_import_batches')->where(['tenant_id' => $tenantId, 'id' => $id])->get()->getRowArray() ?: throw new \OutOfBoundsException('Import not found.');
        $rows = json_decode((string) $b['rows'], true) ?: [];
        return ['id' => (int) $b['id'], 'supplier_id' => (int) $b['supplier_id'], 'mode' => $b['mode'], 'status' => $b['status'], 'rows' => $rows, 'counts' => self::counts($rows), 'notes' => []];
    }

    /**
     * @param list<int>          $accept       row indexes to import
     * @param array<int,array>   $edits        index => corrected fields (service_name, service_type, unit, amount, currency, valid_from, valid_to)
     * @param list<int>          $confirmBig   indexes whose >50% price move the person has confirmed
     * @return array{created:int,updated:int,skipped:int}
     */
    public function commit(int $tenantId, int $userId, int $batchId, array $accept, array $edits = [], array $confirmBig = []): array
    {
        $db = db_connect();
        $db->transStart();
        $b = $db->query('SELECT * FROM rate_import_batches WHERE tenant_id = ? AND id = ? FOR UPDATE', [$tenantId, $batchId])->getRowArray();
        if (! $b) { $db->transComplete(); throw new \OutOfBoundsException('Import not found.'); }
        if ($b['status'] === 'committed') { $db->transComplete(); throw new \DomainException('This import was already applied.'); }
        $rows = json_decode((string) $b['rows'], true) ?: [];
        $supplierId = (int) $b['supplier_id']; $models = (new SupplierRateModel())->setTenant($tenantId);
        $existing = $this->existingFor($tenantId, $supplierId);
        $accept = array_values(array_unique(array_map('intval', $accept)));

        $plan = []; $problems = [];
        foreach ($accept as $i) {
            if (! isset($rows[$i])) { $problems[] = "Row " . ($i + 1) . " does not exist."; continue; }
            $r = $rows[$i];
            if (isset($edits[$i]) && is_array($edits[$i])) {                       // a person corrected it: they are the authority on the price, so no source check
                $n = RateCardRows::normalise($edits[$i] + ['service_name' => $r['service_name'], 'service_type' => $r['service_type'], 'unit' => $r['unit'], 'amount' => $r['amount_major'], 'currency' => $r['currency'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'notes' => $r['notes']]);
                $r = $n['row'] + ['errors' => $n['errors'], 'warnings' => $n['warnings']];
            }
            if (! empty($r['errors'])) { $problems[] = 'Row ' . ($i + 1) . ' (' . ($r['service_name'] ?: 'unnamed') . '): ' . $r['errors'][0]; continue; }
            $ex = $existing[RateCardRows::key($r)] ?? null;
            if ($ex && RateCardRows::bigChange((int) $ex['cost_amount'], (int) $r['cost_amount']) && ! in_array($i, array_map('intval', $confirmBig), true)) {
                $problems[] = 'Row ' . ($i + 1) . ' (' . $r['service_name'] . ') changes the price by more than 50% — confirm it explicitly.'; continue;
            }
            $plan[$i] = [$r, $ex];
        }
        if ($problems) { $db->transComplete(); throw new \InvalidArgumentException(implode(' ', $problems)); }
        if (! $plan) { $db->transComplete(); throw new \InvalidArgumentException('Select at least one row to import.'); }

        $created = 0; $updated = 0; $keys = [];
        foreach ($plan as [$r, $ex]) {
            $k = RateCardRows::key($r);
            if (isset($keys[$k])) { continue; }                                   // two selected rows with the same identity: the first wins
            $keys[$k] = true;
            $meta = json_encode(['import_batch' => $batchId, 'source' => $b['source_name'], 'notes' => $r['notes'] ?: null]);
            if ($ex) {
                $models->update((int) $ex['id'], ['cost_amount' => $r['cost_amount'], 'service_type' => $r['service_type'], 'valid_to' => $r['valid_to'], 'meta' => $meta]); $updated++;
            } else {
                $models->insert(['supplier_id' => $supplierId, 'destination_id' => $b['destination_id'] ?: null, 'service_name' => $r['service_name'], 'service_type' => $r['service_type'], 'unit' => $r['unit'],
                    'currency' => $r['currency'], 'cost_amount' => $r['cost_amount'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'meta' => $meta]); $created++;
            }
        }
        $db->table('rate_import_batches')->where('id', $batchId)->update(['status' => 'committed', 'created_count' => $created, 'updated_count' => $updated, 'committed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $db->transComplete();
        if (! $db->transStatus()) { throw new \RuntimeException('Could not save the rates — nothing was imported.'); }
        AuditLogger::log('rates.import', 'rate_import', $batchId, null, ['supplier_id' => $supplierId, 'created' => $created, 'updated' => $updated], $tenantId, $userId);
        return ['created' => $created, 'updated' => $updated, 'skipped' => count($rows) - $created - $updated];
    }

    public static function counts(array $rows): array
    {
        $c = ['new' => 0, 'change' => 0, 'same' => 0, 'invalid' => 0, 'duplicate_in_file' => 0];
        foreach ($rows as $r) { $c[$r['status']] = ($c[$r['status']] ?? 0) + 1; }
        return $c + ['total' => count($rows)];
    }

    // ---- reading ---------------------------------------------------------------------------------------------------

    /** @return array{0:?array,1:string} [2-D grid when the file is tabular, plain text] */
    private function read(string $content, string $ext): array
    {
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $tmp = tempnam(sys_get_temp_dir(), 'rate'); file_put_contents($tmp, $content);
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp); $reader->setReadDataOnly(true);
                $sheet = $reader->load($tmp)->getActiveSheet();
                $grid = [];
                foreach ($sheet->toArray(null, true, true, false) as $row) { if (array_filter($row, static fn ($v) => $v !== null && $v !== '')) { $grid[] = array_map(static fn ($v) => (string) $v, $row); } if (count($grid) > 3000) { break; } }
            } catch (\Throwable) { throw new \InvalidArgumentException('That spreadsheet could not be read. Save it as .xlsx or .csv and try again.'); }
            finally { @unlink($tmp); }
            return [$grid, implode("\n", array_map(static fn ($r) => implode("\t", $r), $grid))];
        }
        if ($ext === 'pdf') { throw new \InvalidArgumentException('PDFs cannot be read yet — copy the rate table from the PDF and paste it here, or export it to Excel.'); }
        if (preg_match('/^\xEF\xBB\xBF/', $content)) { $content = substr($content, 3); }
        if (! mb_check_encoding($content, 'UTF-8')) { $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252'); }
        $lines = preg_split('/\R/', trim($content)) ?: [];
        $grid = null;
        foreach (["\t", ',', ';', '|'] as $d) {                                  // the delimiter that splits the first rows into the same number (>= 2) of cells; quoted "12,500" survives
            $parsed = array_map(static fn ($l) => str_getcsv($l, $d, '"', ''), array_slice($lines, 0, 6));
            $n = count($parsed[0]);
            if ($n >= 2 && count($parsed) > 1 && count(array_filter($parsed, static fn ($r) => count($r) === $n)) >= min(count($parsed), 2)) { $grid = array_map(static fn ($l) => str_getcsv($l, $d, '"', ''), $lines); break; }
        }
        return [$grid, $content];
    }

    /** @return array{0:list<array>,1:string,2:?string} [raw rows, mode, note] */
    private function extract(string $text, string $supplierName): array
    {
        $rows = []; $aiOk = false; $chunks = $this->chunks($text);
        foreach ($chunks as $chunk) {
            $res = ($this->ai ?? new AiReplyService())->withTimeout(90_000)->generate(
                'You extract supplier rate-card rows for an Indian travel agency from pasted text. Reply with ONE JSON object only: {"rows":[{"service_name":str,"service_type":"hotel|transfer|sightseeing|activity|flight|visa|insurance|meal|other",'
                . '"unit":"per_night|per_pax|per_vehicle|per_day|per_room|flat","amount":number,"currency":"ISO code or null","valid_from":"YYYY-MM-DD|null","valid_to":"YYYY-MM-DD|null","notes":str}]}. '
                . 'Rules: copy each price EXACTLY as printed — never calculate, add taxes, convert currency or average. One row per distinct price (a different room type, vehicle or season is a separate row; put the room type/season in service_name). '
                . 'Skip anything without a printed price. Dates only when the text states them. Do not invent services.',
                [['role' => 'user', 'content' => "Supplier: {$supplierName}\n\n" . $chunk]], 4000, false
            );
            $parsed = $res['success'] ? TravelAiService::extractJson($res['text']) : null;
            if (is_array($parsed['rows'] ?? null)) { $aiOk = true; foreach ($parsed['rows'] as $r) { if (is_array($r)) { $rows[] = $r; } } }
        }
        if ($aiOk) { return [$rows, 'ai', null]; }
        return [RateCardRows::heuristicLines($text), 'lines', 'The AI was not available, so each line with a price was read as “name – price”. Check the preview carefully.'];
    }

    /** @return list<string> split on line boundaries */
    private function chunks(string $text): array
    {
        $out = []; $cur = '';
        foreach (preg_split('/\R/', $text) ?: [] as $l) {
            if (mb_strlen($cur) + mb_strlen($l) > self::CHUNK && $cur !== '') { $out[] = $cur; $cur = ''; }
            $cur .= $l . "\n";
        }
        if (trim($cur) !== '') { $out[] = $cur; }
        return $out;
    }

    /** @return array<string,array> key => existing rate */
    private function existingFor(int $tenantId, int $supplierId): array
    {
        $map = [];
        foreach ((new SupplierRateModel())->setTenant($tenantId)->where('supplier_id', $supplierId)->findAll(5000) as $r) {
            $map[RateCardRows::key(['service_name' => $r['service_name'], 'unit' => $r['unit'], 'currency' => $r['currency'], 'valid_from' => $r['valid_from']])] = $r;
        }
        return $map;
    }
}
