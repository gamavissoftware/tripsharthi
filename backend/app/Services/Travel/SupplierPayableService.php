<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\BookingServiceModel;
use App\Services\Crm\AuditLogger;

/**
 * Money the agency owes suppliers. Every payment is a ledger row; booking_services.paid_amount and
 * bookings.supplier_paid are derived from the ledger inside the same transaction, so they can never drift.
 * Overpaying a service is refused (raise the cost first); cancelled services keep their paid amount visible as "refund due".
 */
final class SupplierPayableService
{
    public const MODES = ['bank', 'upi', 'cash', 'card', 'cheque', 'other'];

    public function __construct(private readonly ?int $now = null) {}
    private function today(): string { return date('Y-m-d', $this->now ?? time()); }

    public function pay(int $tenantId, int $serviceId, array $in, ?int $userId = null): array
    {
        $svc = (new BookingServiceModel())->setTenant($tenantId)->find($serviceId);
        if (! $svc) { throw new \InvalidArgumentException('Service not found.'); }
        if ($svc['status'] === 'cancelled') { throw new \DomainException('This service is cancelled. Record a refund from the supplier with the supplier instead of a payment.'); }
        $fxCur = (string) ($svc['cost_currency'] ?? 'INR');
        $foreign = $fxCur !== 'INR' && $svc['cost_fx'] !== null;
        $amount = (int) round(((float) ($in['amount_rs'] ?? 0)) * 100);            // INR paise: what the bank actually debited
        if ($amount <= 0) { throw new \InvalidArgumentException($foreign ? 'Enter the rupee amount your bank debited for this payment.' : 'Enter the amount paid.'); }
        $fxAmount = null;
        if ($foreign) {
            $fxAmount = Currency::toMinor((float) ($in['fx_amount'] ?? 0), $fxCur);
            if ($fxAmount <= 0) { throw new \InvalidArgumentException("Enter how much you paid the supplier in {$fxCur}."); }
        }
        $date = (string) ($in['paid_on'] ?? $this->today());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || $date > $this->today()) { throw new \InvalidArgumentException('Enter the date you paid (not in the future).'); }
        $mode = strtolower((string) ($in['mode'] ?? 'bank'));
        if (! in_array($mode, self::MODES, true)) { throw new \InvalidArgumentException('Choose how you paid.'); }

        $db = db_connect();
        $db->transStart();
        $row = $db->query('SELECT cost_amount, paid_amount, cost_fx, paid_fx, cost_currency, booking_id, supplier_id FROM booking_services WHERE id = ? AND tenant_id = ? FOR UPDATE', [$serviceId, $tenantId])->getRowArray();
        $realised = null;
        if ($foreign) {
            $outFx = (int) $row['cost_fx'] - (int) $row['paid_fx'];
            if ($fxAmount > $outFx) {
                $db->transComplete();
                throw new \InvalidArgumentException('That is more than is outstanding (' . Currency::format(max(0, $outFx), $fxCur) . '). Raise the service cost first if the supplier is charging more.');
            }
            $realised = Currency::realisedRate($amount, $fxAmount, $fxCur);
            $mkt = (new FxService())->get($tenantId, $fxCur);
            if ($mkt && ! Currency::plausibleChange((float) $mkt['rate'], $realised, 25.0)) {
                $db->transComplete();
                throw new \InvalidArgumentException(sprintf('₹%s for %s works out to ₹%.4f per %s, but the current rate is ₹%.4f. Check the two amounts.', number_format($amount / 100, 2), Currency::format($fxAmount, $fxCur), $realised, $fxCur, $mkt['rate']));
            }
        } else {
            $outstanding = (int) $row['cost_amount'] - (int) $row['paid_amount'];
            if ($amount > $outstanding) {
                $db->transComplete();
                throw new \InvalidArgumentException('That is more than is outstanding (' . number_format(max(0, $outstanding) / 100, 2) . '). Raise the service cost first if the supplier is charging more.');
            }
        }
        $db->table('supplier_payments')->insert(['tenant_id' => $tenantId, 'booking_id' => $row['booking_id'], 'booking_service_id' => $serviceId, 'supplier_id' => $row['supplier_id'], 'amount' => $amount,
            'paid_on' => $date, 'mode' => $mode, 'reference' => isset($in['reference']) ? mb_substr(trim((string) $in['reference']), 0, 120) : null,
            'notes' => isset($in['notes']) ? mb_substr(trim((string) $in['notes']), 0, 255) : null, 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s', $this->now ?? time())]
            + ($foreign ? ['fx_currency' => $fxCur, 'fx_amount' => $fxAmount, 'fx_rate' => $realised] : []));
        $id = (int) $db->insertID();
        $this->rederive($tenantId, $serviceId, (int) $row['booking_id']);
        $db->transComplete();
        if (! $db->transStatus()) { throw new \RuntimeException('Could not record the payment.'); }
        AuditLogger::log('supplier.pay', 'supplier_payment', $id, null, ['service' => $serviceId, 'amount' => $amount], $tenantId, $userId);
        return $this->forService($tenantId, $serviceId);
    }

    public function delete(int $tenantId, int $paymentId, ?int $userId = null): array
    {
        $db = db_connect();
        $p = $db->table('supplier_payments')->where('tenant_id', $tenantId)->where('id', $paymentId)->get()->getRowArray();
        if (! $p) { throw new \InvalidArgumentException('Payment not found.'); }
        $db->transStart();
        $db->table('supplier_payments')->where('tenant_id', $tenantId)->where('id', $paymentId)->delete();
        $this->rederive($tenantId, (int) $p['booking_service_id'], (int) $p['booking_id']);
        $db->transComplete();
        AuditLogger::log('supplier.pay_delete', 'supplier_payment', $paymentId, ['amount' => (int) $p['amount'], 'service' => (int) $p['booking_service_id']], null, $tenantId, $userId);
        return $this->forService($tenantId, (int) $p['booking_service_id']);
    }

    private function rederive(int $tenantId, int $serviceId, int $bookingId): void
    {
        $db = db_connect();
        $db->query('UPDATE booking_services SET paid_amount = (SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE tenant_id = ? AND booking_service_id = ?),
                    paid_fx = (SELECT COALESCE(SUM(fx_amount),0) FROM supplier_payments WHERE tenant_id = ? AND booking_service_id = ?) WHERE id = ? AND tenant_id = ?', [$tenantId, $serviceId, $tenantId, $serviceId, $serviceId, $tenantId]);
        // A foreign-currency service is SETTLED when the full foreign amount has been paid: from then on its cost is what we really paid in INR
        // (so margin is real), and the difference to the quote is the forex variance. If a payment is removed it reverts to the quoted cost.
        $s = $db->table('booking_services')->where('id', $serviceId)->where('tenant_id', $tenantId)->get()->getRowArray();
        if (($s['cost_currency'] ?? 'INR') !== 'INR' && $s['cost_fx'] !== null) {
            $quoted = $s['cost_quoted_inr'] !== null ? (int) $s['cost_quoted_inr'] : (int) $s['cost_amount'];
            if ((int) $s['paid_fx'] >= (int) $s['cost_fx'] && (int) $s['cost_fx'] > 0) {
                $db->table('booking_services')->where('id', $serviceId)->update(['cost_quoted_inr' => $quoted, 'cost_amount' => (int) $s['paid_amount'], 'fx_variance' => (int) $s['paid_amount'] - $quoted]);
            } else {
                $db->table('booking_services')->where('id', $serviceId)->update(['cost_quoted_inr' => null, 'cost_amount' => $quoted, 'fx_variance' => null]);
            }
        }
        $db->query('UPDATE bookings SET supplier_paid = (SELECT COALESCE(SUM(paid_amount),0) FROM booking_services WHERE tenant_id = ? AND booking_id = ?),
                    cost_total = (SELECT COALESCE(SUM(cost_amount),0) FROM booking_services WHERE tenant_id = ? AND booking_id = ? AND status <> \'cancelled\') WHERE id = ? AND tenant_id = ?', [$tenantId, $bookingId, $tenantId, $bookingId, $bookingId, $tenantId]);
    }

    /** INR we expect to pay for what is still owed in a foreign currency: today's market rate, else the rate locked on the quote. */
    private function fxOutstandingInr(int $tenantId, array $svc): int
    {
        $out = max(0, (int) $svc['cost_fx'] - (int) $svc['paid_fx']);
        if ($out === 0) { return 0; }
        $r = (new FxService())->get($tenantId, (string) $svc['cost_currency']);
        $rate = $r ? (float) $r['rate'] : (float) ($svc['fx_rate'] ?? 0);
        return $rate > 0 ? Currency::toInrPaise($out, (string) $svc['cost_currency'], $rate, 0) : 0;
    }

    public function forService(int $tenantId, int $serviceId): array
    {
        $svc = (new BookingServiceModel())->setTenant($tenantId)->find($serviceId);
        $pays = db_connect()->table('supplier_payments')->where('tenant_id', $tenantId)->where('booking_service_id', $serviceId)->orderBy('paid_on', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();
        $fx = ($svc['cost_currency'] ?? 'INR') !== 'INR' && $svc['cost_fx'] !== null;
        return ['service_id' => $serviceId, 'cost' => (int) $svc['cost_amount'], 'paid' => (int) $svc['paid_amount'], 'outstanding' => $fx ? $this->fxOutstandingInr($tenantId, $svc) : max(0, (int) $svc['cost_amount'] - (int) $svc['paid_amount']),
            'fx' => $fx ? ['currency' => $svc['cost_currency'], 'cost' => (int) $svc['cost_fx'], 'paid' => (int) $svc['paid_fx'], 'outstanding' => max(0, (int) $svc['cost_fx'] - (int) $svc['paid_fx']), 'variance' => $svc['fx_variance'] !== null ? (int) $svc['fx_variance'] : null, 'quoted_inr' => $svc['cost_quoted_inr'] !== null ? (int) $svc['cost_quoted_inr'] : null] : null,
            'payments' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'amount' => (int) $p['amount'], 'paid_on' => $p['paid_on'], 'mode' => $p['mode'], 'reference' => $p['reference'], 'notes' => $p['notes'], 'fx_currency' => $p['fx_currency'] ?? null, 'fx_amount' => isset($p['fx_amount']) ? (int) $p['fx_amount'] : null, 'fx_rate' => isset($p['fx_rate']) ? (float) $p['fx_rate'] : null], $pays)];
    }

    /** Outstanding supplier dues, grouped by supplier. $filter: all | overdue | week (due in 7 days). Cancelled bookings/services excluded (reported as refunds due). */
    public function payables(int $tenantId, string $filter = 'all'): array
    {
        $today = $this->today();
        $sql = "SELECT s.id, s.booking_id, s.title, s.service_type, s.service_date, s.status, s.cost_amount, s.paid_amount, s.cost_currency, s.cost_fx, s.paid_fx, s.fx_rate, s.pay_by, s.supplier_id, s.confirmation_no,
                       b.booking_ref, b.travel_start, sup.name AS supplier_name
                FROM booking_services s JOIN bookings b ON b.id = s.booking_id AND b.tenant_id = s.tenant_id
                LEFT JOIN suppliers sup ON sup.id = s.supplier_id AND sup.tenant_id = s.tenant_id
                WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND b.deleted_at IS NULL AND b.status <> 'cancelled' AND s.status <> 'cancelled' AND ((s.cost_currency = 'INR' AND s.cost_amount > s.paid_amount) OR (s.cost_currency <> 'INR' AND s.cost_fx > s.paid_fx))
                ORDER BY COALESCE(s.pay_by, b.travel_start, '9999-12-31'), s.id";
        $groups = []; $exposure = []; $tot = ['outstanding' => 0, 'overdue' => 0, 'due_7d' => 0];
        foreach (db_connect()->query($sql, [$tenantId])->getResultArray() as $r) {
            $isFx = $r['cost_currency'] !== 'INR' && $r['cost_fx'] !== null;
            $out = $isFx ? $this->fxOutstandingInr($tenantId, $r) : (int) $r['cost_amount'] - (int) $r['paid_amount'];
            if ($isFx) {
                $c = (string) $r['cost_currency'];
                $exposure[$c] ??= ['currency' => $c, 'name' => Currency::name($c), 'outstanding_fx' => 0, 'inr_estimate' => 0, 'services' => 0];
                $exposure[$c]['outstanding_fx'] += (int) $r['cost_fx'] - (int) $r['paid_fx']; $exposure[$c]['inr_estimate'] += $out; $exposure[$c]['services']++;
            }
            $due = $r['pay_by'] ?: ($r['travel_start'] ? date('Y-m-d', strtotime($r['travel_start'] . ' -7 days')) : null);   // no explicit date: settle a week before travel
            $over = $due !== null && $due < $today;
            $soon = $due !== null && ! $over && $due <= date('Y-m-d', strtotime($today . ' +7 days'));
            if (($filter === 'overdue' && ! $over) || ($filter === 'week' && ! $over && ! $soon)) { continue; }
            $k = (int) ($r['supplier_id'] ?? 0);
            $groups[$k] ??= ['supplier_id' => $k ?: null, 'supplier' => $r['supplier_name'] ?: 'No supplier assigned', 'outstanding' => 0, 'items' => []];
            $groups[$k]['outstanding'] += $out;
            $groups[$k]['items'][] = ['service_id' => (int) $r['id'], 'booking_id' => (int) $r['booking_id'], 'booking_ref' => $r['booking_ref'], 'title' => $r['title'], 'type' => $r['service_type'], 'status' => $r['status'],
                'cost' => (int) $r['cost_amount'], 'paid' => (int) $r['paid_amount'], 'outstanding' => $out,
                'fx' => $isFx ? ['currency' => (string) $r['cost_currency'], 'cost' => (int) $r['cost_fx'], 'paid' => (int) $r['paid_fx'], 'outstanding' => (int) $r['cost_fx'] - (int) $r['paid_fx']] : null, 'due' => $due, 'due_is_default' => $r['pay_by'] === null, 'overdue' => $over, 'confirmation_no' => $r['confirmation_no']];
            $tot['outstanding'] += $out; if ($over) { $tot['overdue'] += $out; } elseif ($soon) { $tot['due_7d'] += $out; }
        }
        usort($groups, static fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);
        $refunds = db_connect()->query("SELECT COUNT(*) n, COALESCE(SUM(s.paid_amount),0) amt FROM booking_services s JOIN bookings b ON b.id = s.booking_id AND b.tenant_id = s.tenant_id
            WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.paid_amount > 0 AND (s.status = 'cancelled' OR b.status = 'cancelled')", [$tenantId])->getRowArray();
        return ['filter' => $filter, 'totals' => $tot, 'fx_exposure' => array_values($exposure), 'suppliers' => array_values($groups), 'refunds_due' => ['count' => (int) $refunds['n'], 'amount' => (int) $refunds['amt']]];
    }
}
