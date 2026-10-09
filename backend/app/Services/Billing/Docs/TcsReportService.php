<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

use App\Models\BookingModel;
use App\Services\Crm\AuditLogger;

/** TCS collected on overseas packages, per quarter, with PAN completeness and challan (deposit) tracking — the data a 27EQ return is prepared from. */
final class TcsReportService
{
    public function __construct(private readonly ?int $now = null) {}
    private function today(): string { return date('Y-m-d', $this->now ?? time()); }

    public function report(int $tenantId, int $fy, int $q): array
    {
        [$from, $to] = TcsLedger::quarter($fy, $q);
        $db = db_connect();
        $bookings = $db->query("SELECT b.id, b.booking_ref, b.title, b.status, b.total_amount, b.tcs_amount, b.subtotal, b.gst_amount, b.customer_pan, c.name AS customer
            FROM bookings b LEFT JOIN contacts c ON c.id = b.contact_id
            WHERE b.tenant_id = ? AND b.deleted_at IS NULL AND b.tcs_amount > 0", [$tenantId])->getResultArray();
        $rows = []; $warnings = []; $cancelled = 0;
        foreach ($bookings as $b) {
            $pays = $db->query("SELECT id, amount, paid_at FROM booking_payments WHERE tenant_id = ? AND booking_id = ? AND status = 'paid' AND paid_at IS NOT NULL AND deleted_at IS NULL", [$tenantId, $b['id']])->getResultArray();
            $base = (int) $b['subtotal'] + (int) $b['gst_amount'];
            $rate = $base > 0 ? round((int) $b['tcs_amount'] * 100 / $base, 2) : 0.0;
            foreach (TcsLedger::split((int) $b['total_amount'], (int) $b['tcs_amount'], $pays) as $c) {
                if ($c['date'] < $from || $c['date'] > $to || $c['tcs'] <= 0) { continue; }
                $rows[] = ['booking_id' => (int) $b['id'], 'booking_ref' => $b['booking_ref'], 'customer' => (string) $b['customer'], 'pan' => (string) ($b['customer_pan'] ?? ''), 'date' => $c['date'],
                    'period' => substr($c['date'], 0, 7), 'received' => $c['received'], 'tcs' => $c['tcs'], 'rate' => $rate, 'booking_status' => $b['status']];
                if ($b['status'] === 'cancelled') { $cancelled++; }
            }
        }
        usort($rows, static fn ($a, $b) => [$a['date'], $a['booking_id']] <=> [$b['date'], $b['booking_id']]);

        $challans = $db->query('SELECT * FROM tcs_challans WHERE tenant_id = ? AND period >= ? AND period <= ? ORDER BY deposit_date, id', [$tenantId, substr($from, 0, 7), substr($to, 0, 7)])->getResultArray();
        $months = [];
        foreach (TcsLedger::months($fy, $q) as $m) {
            $coll = array_sum(array_map(static fn ($r) => $r['period'] === $m ? $r['tcs'] : 0, $rows));
            $mc = array_values(array_filter($challans, static fn ($c) => $c['period'] === $m));
            $dep = array_sum(array_map(static fn ($c) => (int) $c['amount'], $mc));
            $due = TcsLedger::dueDate($m);
            $months[] = ['period' => $m, 'collected' => (int) $coll, 'deposited' => (int) $dep, 'outstanding' => max(0, (int) $coll - (int) $dep), 'due_date' => $due,
                'status' => TcsLedger::status((int) $coll, (int) $dep, $due, $this->today()), 'challans' => array_map(static fn ($c) => ['id' => (int) $c['id'], 'amount' => (int) $c['amount'], 'bsr_code' => $c['bsr_code'], 'challan_serial' => $c['challan_serial'], 'deposit_date' => $c['deposit_date'], 'notes' => $c['notes']], $mc)];
            if ($dep > $coll) { $warnings[] = "$m: challans (" . number_format($dep / 100, 2) . ") exceed TCS collected (" . number_format($coll / 100, 2) . ') — check the amounts.'; }
        }
        $noPan = count(array_unique(array_map(static fn ($r) => $r['booking_id'], array_filter($rows, static fn ($r) => ! Gst::validPan($r['pan'])))));
        if ($noPan > 0) { $warnings[] = "$noPan booking(s) have no valid customer PAN. A 27EQ needs the PAN of every customer; without it the Income-tax Act requires a higher TCS rate (s.206CC) — ask your CA."; }
        if ($cancelled > 0) { $warnings[] = "$cancelled collection(s) belong to cancelled bookings. Refunds of TCS are NOT tracked here — settle those with your CA."; }
        foreach ($months as $m) { if ($m['status'] === 'overdue') { $warnings[] = "TCS for {$m['period']} was due on {$m['due_date']} and is not fully deposited (late deposit attracts interest)."; } }

        return ['fy' => $fy, 'fy_label' => $fy . '-' . substr((string) ($fy + 1), 2), 'quarter' => $q, 'from' => $from, 'to' => $to, 'months' => $months, 'rows' => $rows,
            'totals' => ['received' => array_sum(array_column($rows, 'received')), 'tcs' => array_sum(array_column($rows, 'tcs')), 'deposited' => array_sum(array_column($months, 'deposited'))], 'warnings' => $warnings];
    }

    public function setPan(int $tenantId, int $bookingId, string $pan): array
    {
        $pan = strtoupper(trim($pan));
        if ($pan !== '' && ! Gst::validPan($pan)) { throw new \InvalidArgumentException('That PAN is not valid (format: ABCDE1234F).'); }
        $b = (new BookingModel())->setTenant($tenantId)->find($bookingId);
        if (! $b) { throw new \InvalidArgumentException('Booking not found.'); }
        db_connect()->table('bookings')->where('tenant_id', $tenantId)->where('id', $bookingId)->update(['customer_pan' => $pan === '' ? null : $pan]);
        AuditLogger::log('booking.pan', 'booking', $bookingId, null, ['pan_set' => $pan !== ''], $tenantId, null);
        return ['booking_id' => $bookingId, 'pan' => $pan];
    }

    public function addChallan(int $tenantId, array $in, ?int $userId): array
    {
        $period = (string) ($in['period'] ?? '');
        $amount = (int) round(((float) ($in['amount_rs'] ?? 0)) * 100);
        $bsr = trim((string) ($in['bsr_code'] ?? '')); $serial = trim((string) ($in['challan_serial'] ?? '')); $date = (string) ($in['deposit_date'] ?? '');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) { throw new \InvalidArgumentException('Choose the month this challan pays for.'); }
        if ($amount <= 0) { throw new \InvalidArgumentException('Enter the amount deposited.'); }
        if (! preg_match('/^\d{7}$/', $bsr)) { throw new \InvalidArgumentException('BSR code is 7 digits (printed on the challan).'); }
        if (! preg_match('/^\d{1,5}$/', $serial)) { throw new \InvalidArgumentException('Challan serial number is up to 5 digits.'); }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || $date > $this->today()) { throw new \InvalidArgumentException('Enter the date the challan was paid (not in the future).'); }
        $db = db_connect();
        $db->table('tcs_challans')->insert(['tenant_id' => $tenantId, 'period' => $period, 'amount' => $amount, 'bsr_code' => $bsr, 'challan_serial' => $serial, 'deposit_date' => $date,
            'notes' => isset($in['notes']) ? mb_substr(trim((string) $in['notes']), 0, 255) : null, 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s', $this->now ?? time())]);
        $id = (int) $db->insertID();
        AuditLogger::log('tcs.challan_add', 'tcs_challan', $id, null, ['period' => $period, 'amount' => $amount], $tenantId, $userId);
        return ['id' => $id];
    }

    public function deleteChallan(int $tenantId, int $id, ?int $userId): void
    {
        $db = db_connect();
        $row = $db->table('tcs_challans')->where('tenant_id', $tenantId)->where('id', $id)->get()->getRowArray();
        if (! $row) { throw new \InvalidArgumentException('Challan not found.'); }
        $db->table('tcs_challans')->where('tenant_id', $tenantId)->where('id', $id)->delete();
        AuditLogger::log('tcs.challan_delete', 'tcs_challan', $id, ['period' => $row['period'], 'amount' => (int) $row['amount'], 'bsr_code' => $row['bsr_code']], null, $tenantId, $userId);
    }

    /** Collectee-wise CSV (one line per collection) for the CA / return-preparation software. */
    public function csv(int $tenantId, int $fy, int $q): string
    {
        $r = $this->report($tenantId, $fy, $q);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Collection date', 'Booking', 'Customer', 'PAN', 'Amount received (incl. TCS)', 'TCS rate %', 'TCS collected', 'Section'], ',', '"', '');
        foreach ($r['rows'] as $x) {
            fputcsv($out, [$x['date'], GstReturns::csvSafe($x['booking_ref']), GstReturns::csvSafe($x['customer']), $x['pan'], number_format($x['received'] / 100, 2, '.', ''), (string) $x['rate'], number_format($x['tcs'] / 100, 2, '.', ''), '206C(1G)'], ',', '"', '');
        }
        fputcsv($out, [], ',', '"', '');
        fputcsv($out, ['Challans', 'Month', 'Amount', 'BSR code', 'Challan serial', 'Deposit date'], ',', '"', '');
        foreach ($r['months'] as $m) { foreach ($m['challans'] as $c) { fputcsv($out, ['', $m['period'], number_format($c['amount'] / 100, 2, '.', ''), $c['bsr_code'], $c['challan_serial'], $c['deposit_date']], ',', '"', ''); } }
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }
}
