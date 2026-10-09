<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Services\Crm\AuditLogger;

/**
 * Per-tenant exchange rates (INR per 1 unit of a currency) + a cost buffer.
 *
 *  - A rate is either MANUAL (the agent's own bank/forex-desk rate; never overwritten) or AUTO (daily ECB reference rate via Frankfurter,
 *    plus fixed pegs for AED/SAR/QAR/OMR/BHD/BTN). Typing a rate switches it to manual; "Resume auto" switches back.
 *  - A big jump (default > 25 %) is refused unless explicitly confirmed — it is almost always a typo (8.35 for 83.5).
 *  - Every change is written to fx_rate_history. A stale rate (> 3 days) is flagged, never silently used as if fresh.
 *  - The BUFFER (default 2 %) is added to COSTS only (we pay suppliers later, so the market may move against us).
 *    Customer-facing equivalents use the plain mid rate.
 */
final class FxService
{
    public const STALE_DAYS = 3;
    /** units of the currency per 1 USD (hard pegs) */
    public const USD_PEGS = ['AED' => 3.6725, 'SAR' => 3.75, 'QAR' => 3.64, 'OMR' => 0.3845, 'BHD' => 0.376];

    /** @param null|callable(string,string,array):array{status:int,body:string} $http */
    public function __construct(private readonly mixed $http = null, private readonly ?int $now = null) {}
    private function ts(): int { return $this->now ?? time(); }
    private function stamp(): string { return date('Y-m-d H:i:s', $this->ts()); }

    private function view(array $r): array
    {
        $age = $r['as_of'] ? (int) floor(($this->ts() - strtotime((string) $r['as_of'])) / 86400) : null;
        $rate = (float) $r['rate']; $buf = (float) $r['buffer_pct'];
        return ['currency' => $r['currency'], 'name' => Currency::name($r['currency']), 'rate' => $rate, 'buffer_pct' => $buf, 'cost_rate' => round($rate * (1 + $buf / 100), 8), 'source' => $r['source'], 'as_of' => $r['as_of'],
            'age_days' => $age, 'stale' => $age !== null && $age > self::STALE_DAYS, 'auto_available' => self::autoAvailable($r['currency'])];
    }

    public static function autoAvailable(string $c): bool { return $c !== 'INR'; }   // ECB list or a peg covers most; unsupported ones report a clear message on refresh

    public function list(int $tenantId): array
    {
        return array_map(fn ($r) => $this->view($r), db_connect()->table('fx_rates')->where('tenant_id', $tenantId)->orderBy('currency')->get()->getResultArray());
    }

    public function get(int $tenantId, string $currency): ?array
    {
        $r = db_connect()->table('fx_rates')->where('tenant_id', $tenantId)->where('currency', $currency)->get()->getRowArray();
        return $r ? $this->view($r) : null;
    }

    /** The rate a COST in this currency must use. @throws \RuntimeException when none is set */
    public function costRate(int $tenantId, string $currency): array
    {
        $r = $this->get($tenantId, $currency);
        if (! $r) { throw new \RuntimeException("Set the {$currency} exchange rate first (Settings → Exchange rates), then price this line again."); }
        return $r;
    }

    public function setRate(int $tenantId, string $currency, float $rate, ?float $bufferPct = null, ?int $userId = null, bool $confirmBigChange = false, string $source = 'manual'): array
    {
        if (! Currency::valid($currency) || $currency === 'INR') { throw new \InvalidArgumentException('Choose a foreign currency from the list.'); }
        if (! (is_finite($rate) && $rate > 0 && $rate < 1_000_000)) { throw new \InvalidArgumentException('Enter the exchange rate as rupees per 1 ' . $currency . ' (a number above zero).'); }
        if ($bufferPct !== null && ($bufferPct < 0 || $bufferPct > 20)) { throw new \InvalidArgumentException('The forex buffer must be between 0% and 20%.'); }
        $db = db_connect();
        $cur = $db->table('fx_rates')->where('tenant_id', $tenantId)->where('currency', $currency)->get()->getRowArray();
        if ($cur && ! $confirmBigChange && ! Currency::plausibleChange((float) $cur['rate'], $rate)) {
            throw new \DomainException(sprintf('That is a %.0f%% change from the current %s rate (₹%s). If it is correct, confirm the change.', abs($rate - (float) $cur['rate']) / (float) $cur['rate'] * 100, $currency, rtrim(rtrim(number_format((float) $cur['rate'], 6, '.', ''), '0'), '.')));
        }
        $row = ['rate' => $rate, 'source' => $source, 'as_of' => $this->stamp(), 'updated_by' => $userId, 'updated_at' => $this->stamp()] + ($bufferPct !== null ? ['buffer_pct' => $bufferPct] : []);
        if ($cur) { $db->table('fx_rates')->where('id', $cur['id'])->update($row); }
        else { $db->table('fx_rates')->insert($row + ['tenant_id' => $tenantId, 'currency' => $currency, 'buffer_pct' => $bufferPct ?? 2.0, 'created_at' => $this->stamp()]); }
        $db->table('fx_rate_history')->insert(['tenant_id' => $tenantId, 'currency' => $currency, 'rate' => $rate, 'source' => $source, 'recorded_at' => $this->stamp(), 'recorded_by' => $userId]);
        AuditLogger::log('fx.rate', 'fx_rate', null, $cur ? ['rate' => (float) $cur['rate']] : null, ['currency' => $currency, 'rate' => $rate, 'source' => $source], $tenantId, $userId);
        return $this->get($tenantId, $currency);
    }

    public function setBuffer(int $tenantId, string $currency, float $bufferPct, ?int $userId = null): array
    {
        if ($bufferPct < 0 || $bufferPct > 20) { throw new \InvalidArgumentException('The forex buffer must be between 0% and 20%.'); }
        if (! $this->get($tenantId, $currency)) { throw new \InvalidArgumentException('Add the currency first.'); }
        db_connect()->table('fx_rates')->where('tenant_id', $tenantId)->where('currency', $currency)->update(['buffer_pct' => $bufferPct, 'updated_by' => $userId, 'updated_at' => $this->stamp()]);
        return $this->get($tenantId, $currency);
    }

    public function resumeAuto(int $tenantId, string $currency): array
    {
        if (! $this->get($tenantId, $currency)) { throw new \InvalidArgumentException('Add the currency first.'); }
        db_connect()->table('fx_rates')->where('tenant_id', $tenantId)->where('currency', $currency)->update(['source' => 'auto', 'updated_at' => $this->stamp()]);
        $this->refresh($tenantId, [$currency], true);
        return $this->get($tenantId, $currency);
    }

    /** A currency still referenced by unfinished work cannot be removed. */
    public function remove(int $tenantId, string $currency, ?int $userId = null): void
    {
        $db = db_connect();
        $items = (int) $db->query("SELECT COUNT(*) n FROM itinerary_items i JOIN itineraries t ON t.id = i.itinerary_id WHERE i.tenant_id = ? AND i.cost_currency = ? AND t.deleted_at IS NULL AND i.deleted_at IS NULL AND t.status IN ('draft','sent','viewed')", [$tenantId, $currency])->getRowArray()['n'];
        $owed = (int) $db->query("SELECT COUNT(*) n FROM booking_services WHERE tenant_id = ? AND cost_currency = ? AND deleted_at IS NULL AND status <> 'cancelled' AND COALESCE(cost_fx,0) > paid_fx", [$tenantId, $currency])->getRowArray()['n'];
        if ($items > 0 || $owed > 0) { throw new \DomainException("{$currency} is still used by {$items} open quote line(s) and {$owed} unpaid supplier service(s). Finish or remove those first."); }
        $db->table('fx_rates')->where('tenant_id', $tenantId)->where('currency', $currency)->delete();
        AuditLogger::log('fx.remove', 'fx_rate', null, ['currency' => $currency], null, $tenantId, $userId);
    }

    public function history(int $tenantId, string $currency, int $limit = 30): array
    {
        return array_map(static fn ($h) => ['rate' => (float) $h['rate'], 'source' => $h['source'], 'at' => $h['recorded_at']],
            db_connect()->table('fx_rate_history')->where('tenant_id', $tenantId)->where('currency', $currency)->orderBy('id', 'DESC')->limit(max(1, min(200, $limit)))->get()->getResultArray());
    }

    // ---- automatic refresh ---------------------------------------------------------------------------------------------

    /**
     * Pure: INR-per-unit rates from Frankfurter's `latest?base=INR` body (1 INR = x CCY) plus the fixed pegs.
     * @return array<string,float> currency => INR per 1 unit
     */
    public static function parseFeed(array $body): array
    {
        $out = [];
        $per = (array) ($body['rates'] ?? []);
        foreach ($per as $c => $x) { if (is_numeric($x) && (float) $x > 0 && Currency::valid((string) $c) && $c !== 'INR') { $out[(string) $c] = round(1 / (float) $x, 8); } }
        if (isset($out['USD'])) { foreach (self::USD_PEGS as $c => $perUsd) { $out[$c] = round($out['USD'] / $perUsd, 8); } }
        $out['BTN'] = 1.0;                                                // the ngultrum is pegged 1:1 to the rupee
        return $out;
    }

    /**
     * Update this tenant's AUTO currencies (or only $only). Manual rates are never touched. An implausible jump is skipped and reported.
     * @return array{updated:list<string>,skipped:array<string,string>,error:?string}
     */
    public function refresh(int $tenantId, ?array $only = null, bool $force = false): array
    {
        $out = ['updated' => [], 'skipped' => [], 'error' => null];
        $rows = db_connect()->table('fx_rates')->where('tenant_id', $tenantId)->where('source', 'auto')->get()->getResultArray();
        if ($only !== null) { $rows = array_values(array_filter($rows, static fn ($r) => in_array($r['currency'], $only, true))); }
        if (! $rows) { return $out; }
        try {
            $feed = self::parseFeed($this->fetch());
        } catch (\Throwable $e) { $out['error'] = 'Could not fetch rates: ' . $e->getMessage(); return $out; }
        foreach ($rows as $r) {
            $c = $r['currency'];
            if (! isset($feed[$c])) { $out['skipped'][$c] = 'No automatic source for this currency — enter its rate manually.'; continue; }
            if (! $force && ! Currency::plausibleChange((float) $r['rate'], $feed[$c], 15.0)) { $out['skipped'][$c] = 'The new rate is more than 15% away from the current one, so it was not applied. Check it and set it manually.'; continue; }
            db_connect()->table('fx_rates')->where('id', $r['id'])->update(['rate' => $feed[$c], 'as_of' => $this->stamp(), 'updated_at' => $this->stamp()]);
            db_connect()->table('fx_rate_history')->insert(['tenant_id' => $tenantId, 'currency' => $c, 'rate' => $feed[$c], 'source' => 'auto', 'recorded_at' => $this->stamp(), 'recorded_by' => null]);
            $out['updated'][] = $c;
        }
        return $out;
    }

    /** Add a currency using the automatic rate (falls back to a clear error telling the user to type one). */
    public function addAuto(int $tenantId, string $currency, ?int $userId = null): array
    {
        if (! Currency::valid($currency) || $currency === 'INR') { throw new \InvalidArgumentException('Choose a foreign currency from the list.'); }
        try { $feed = self::parseFeed($this->fetch()); } catch (\Throwable $e) { throw new \DomainException('Could not fetch a live rate (' . $e->getMessage() . '). Enter the rate manually.'); }
        if (! isset($feed[$currency])) { throw new \DomainException("There is no automatic source for {$currency}. Enter its rate manually."); }
        $r = $this->setRate($tenantId, $currency, $feed[$currency], null, $userId, true, 'auto');
        return $r;
    }

    private function fetch(): array
    {
        $http = $this->http ?? [ConversionFeedbackService::class, 'defaultHttp'];
        $res = $http('GET', 'https://api.frankfurter.dev/v1/latest?base=INR', []);
        $body = json_decode((string) $res['body'], true);
        if ($res['status'] >= 400 || ! is_array($body) || empty($body['rates'])) { throw new \RuntimeException('the rate service returned an error (HTTP ' . $res['status'] . ')'); }
        return $body;
    }

    /** Cron: every tenant that has an AUTO currency. @return array<int,array> */
    public function refreshAll(): array
    {
        $out = [];
        foreach (db_connect()->table('fx_rates')->select('tenant_id')->where('source', 'auto')->distinct()->get()->getResultArray() as $t) { $out[(int) $t['tenant_id']] = $this->refresh((int) $t['tenant_id']); }
        return $out;
    }
}
