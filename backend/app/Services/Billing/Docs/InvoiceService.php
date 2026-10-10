<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

use App\Models\BookingModel;
use App\Models\BookingPaymentModel;
use App\Models\ContactModel;
use App\Models\InvoiceModel;
use App\Models\ItineraryModel;
use App\Models\TripModel;
use App\Services\Crm\AuditLogger;

/**
 * Issues GST tax invoices, bills of supply, credit notes and payment receipts.
 *
 * Invariants (each one is tested):
 *  - NUMBERING IS GAP-FREE per tenant + series + financial year: the counter row is locked, incremented, the PDF
 *    rendered and the file written, all inside ONE transaction. Any failure rolls the number back.
 *  - ISSUED DOCUMENTS NEVER CHANGE: seller/buyer/lines are snapshots; the PDF is stored once (sha256 recorded) and
 *    served from storage; corrections are credit notes.
 *  - A tax invoice's total must equal what the customer was quoted/charged (booking total), to the paisa.
 *  - No GSTIN => Bill of Supply with no GST — and never when the booking itself charges GST.
 *  - A credit note can never exceed what is left of the invoice it corrects.
 */
final class InvoiceService
{
    public function __construct(private readonly ?int $now = null, private readonly bool $images = true) {}

    private function today(): string { return date('Y-m-d', $this->now ?? time()); }

    // ---- tax invoice / bill of supply ------------------------------------------------------------------------

    /** @param array $in buyer: name*, address_line1, address_line2, city, state, pincode, gstin, phone, email */
    public function issueForBooking(int $tenantId, int $bookingId, array $in, ?int $userId = null): array
    {
        $booking = (new BookingModel())->setTenant($tenantId)->find($bookingId);
        if (! $booking || $booking['status'] === 'cancelled') { throw new \DomainException('Booking not found or cancelled.'); }
        $it = $booking['itinerary_id'] ? (new ItineraryModel())->setTenant($tenantId)->find((int) $booking['itinerary_id']) : null;
        $trip = $booking['trip_id'] ? (new TripModel())->setTenant($tenantId)->find((int) $booking['trip_id']) : null;

        $profile = (new BusinessProfileService())->get($tenantId);
        $registered = ! empty($profile['gstin']);
        $docType = $registered ? 'tax_invoice' : 'bill_of_supply';
        if ($missing = (new BusinessProfileService())->missingFor($profile, $docType)) {
            throw new \InvalidArgumentException('Complete your business profile first: ' . implode(', ', $missing) . '.');
        }
        if ($e = BusinessProfileService::validate($profile)) { throw new \InvalidArgumentException(implode(' ', $e)); }

        $existing = $this->activeInvoice($tenantId, $bookingId);
        if ($existing) { throw new \DomainException("This booking is already invoiced ({$existing['number']}). To change it, issue a credit note against that invoice."); }

        $buyer = $this->buyer($tenantId, $booking, $in);
        $seller = $this->seller($profile);
        $taxable = (int) $booking['subtotal']; $gst = (int) $booking['gst_amount']; $tcs = (int) $booking['tcs_amount'];
        if (! $registered && $gst > 0) {
            throw new \DomainException('This booking charges GST, but your profile has no GSTIN, so a tax invoice cannot be issued. Add your GSTIN in Business profile — or re-quote with GST 0% if you are not GST-registered.');
        }
        $total = $taxable + ($registered ? $gst : 0) + $tcs;
        if ($total !== (int) $booking['total_amount']) {
            throw new \DomainException('The invoice total would differ from the booking total (' . DocFormat::inr((int) $booking['total_amount']) . '). Reopen the booking and check its pricing.');
        }

        $place = $registered ? Gst::placeOfSupply($buyer['state_code'] ?: null, $seller['state_code']) : null;
        $split = $registered ? Gst::split($gst, $seller['state_code'], $place) : ['supply' => 'none', 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
        $rate = $registered ? (float) ($it['gst_rate'] ?? 0) : 0.0;
        $pax = (int) ($trip['adults'] ?? 0) + (int) ($trip['children'] ?? 0);

        $line = ['title' => 'Tour package: ' . ($it['title'] ?? $booking['title']),
            'detail' => implode(' · ', array_filter([(string) ($trip['destination_text'] ?? ''), $booking['travel_start'] ? DocFormat::date($booking['travel_start']) . ($booking['travel_end'] ? ' – ' . DocFormat::date($booking['travel_end']) : '') : '',
                $pax > 0 ? $pax . ' traveller' . ($pax > 1 ? 's' : '') : '', 'Booking ' . $booking['booking_ref']]))];

        return $this->insertDocument($tenantId, $docType, 'invoice', $profile['invoice_prefix'] ?: 'INV', [
            'booking_id' => $bookingId, 'seller' => $seller, 'buyer' => $buyer, 'lines' => ['items' => [$line], 'paid_at_issue' => (int) $booking['paid_amount']],
            'place_of_supply' => $place, 'supply_type' => $split['supply'], 'sac' => $registered ? ($profile['sac_code'] ?: '998554') : null,
            'taxable_value' => $taxable, 'gst_rate' => $rate, 'cgst' => $split['cgst'], 'sgst' => $split['sgst'], 'igst' => $split['igst'],
            'tcs_rate' => (float) ($it['tcs_rate'] ?? 0), 'tcs' => $tcs, 'total' => $total, 'booking_ref' => $booking['booking_ref'], 'reason' => null,
        ], $profile, $userId);
    }

    // ---- credit note ------------------------------------------------------------------------------------------

    /** @param array $opts reason* (string), and either full=true or taxable_value (paise) */
    public function creditNote(int $tenantId, int $invoiceId, array $opts, ?int $userId = null): array
    {
        $inv = (new InvoiceModel())->setTenant($tenantId)->find($invoiceId);
        if (! $inv || ! in_array($inv['doc_type'], ['tax_invoice', 'bill_of_supply'], true)) { throw new \DomainException('Credit notes can only be issued against a tax invoice or bill of supply.'); }
        $reason = trim((string) ($opts['reason'] ?? ''));
        if ($reason === '') { throw new \InvalidArgumentException('Say why the credit note is being issued.'); }

        $done = db_connect()->query("SELECT COALESCE(SUM(taxable_value),0) t, COALESCE(SUM(cgst),0) c, COALESCE(SUM(sgst),0) s, COALESCE(SUM(igst),0) i, COALESCE(SUM(tcs),0) x
                                       FROM invoices WHERE tenant_id = ? AND related_id = ? AND doc_type = 'credit_note' AND status = 'issued'", [$tenantId, $invoiceId])->getRowArray();
        $remaining = (int) $inv['taxable_value'] - (int) $done['t'];
        if ($remaining <= 0) { throw new \DomainException('This invoice has already been fully credited.'); }

        $full = ! empty($opts['full']);
        $amount = $full ? $remaining : (int) ($opts['taxable_value'] ?? 0);
        if ($amount <= 0) { throw new \InvalidArgumentException('Enter the amount (before tax) to credit.'); }
        if ($amount > $remaining) { throw new \DomainException('Credit cannot exceed the remaining taxable value of ' . DocFormat::inr($remaining) . '.'); }
        $isFinal = $amount === $remaining;

        // Proportional tax; the LAST credit takes the exact remainder so the invoice nets to zero with no rounding drift.
        if ($inv['doc_type'] === 'bill_of_supply') { $gst = $cgst = $sgst = $igst = 0; }
        else {
            $gstTotal = (int) $inv['cgst'] + (int) $inv['sgst'] + (int) $inv['igst'];
            $gstDone = (int) $done['c'] + (int) $done['s'] + (int) $done['i'];
            $gst = $isFinal ? $gstTotal - $gstDone : (int) round($gstTotal * $amount / (int) $inv['taxable_value']);
            $s = Gst::split($gst, (string) json_decode($inv['seller'], true)['state_code'], (string) $inv['place_of_supply']);
            [$cgst, $sgst, $igst] = [$s['cgst'], $s['sgst'], $s['igst']];
        }
        $tcs = $isFinal ? (int) $inv['tcs'] - (int) $done['x'] : (int) round((int) $inv['tcs'] * $amount / (int) $inv['taxable_value']);
        $profile = (new BusinessProfileService())->get($tenantId);

        return $this->insertDocument($tenantId, 'credit_note', 'credit_note', $profile['credit_prefix'] ?: 'CN', [
            'booking_id' => (int) $inv['booking_id'], 'related_id' => $invoiceId, 'seller' => json_decode($inv['seller'], true), 'buyer' => json_decode($inv['buyer'], true),
            'lines' => ['items' => [['title' => 'Credit against invoice ' . $inv['number'], 'detail' => $reason]]],
            'place_of_supply' => $inv['place_of_supply'], 'supply_type' => $inv['supply_type'], 'sac' => $inv['sac'], 'taxable_value' => $amount, 'gst_rate' => (float) $inv['gst_rate'],
            'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'tcs_rate' => (float) $inv['tcs_rate'], 'tcs' => $tcs, 'total' => $amount + $cgst + $sgst + $igst + $tcs,
            'booking_ref' => (string) (json_decode($inv['lines'], true)['booking_ref'] ?? ''), 'reason' => $reason, 'original' => $inv,
        ], $profile, $userId);
    }

    // ---- payment receipt --------------------------------------------------------------------------------------

    public function receipt(int $tenantId, int $paymentId, ?int $userId = null): array
    {
        $pay = (new BookingPaymentModel())->setTenant($tenantId)->find($paymentId);
        if (! $pay) { throw new \InvalidArgumentException('Payment not found.'); }
        if ($pay['status'] !== 'paid') { throw new \DomainException('A receipt can only be issued for a payment that has been received.'); }
        $have = (new InvoiceModel())->setTenant($tenantId)->where('payment_id', $paymentId)->where('doc_type', 'receipt')->first();
        if ($have) { return $have; }                                   // idempotent: one receipt per payment
        $booking = (new BookingModel())->setTenant($tenantId)->find((int) $pay['booking_id']);
        $profile = (new BusinessProfileService())->get($tenantId);
        if ($missing = (new BusinessProfileService())->missingFor($profile, 'receipt')) { throw new \InvalidArgumentException('Complete your business profile first: ' . implode(', ', $missing) . '.'); }
        $inv = $this->activeInvoice($tenantId, (int) $booking['id']);

        return $this->insertDocument($tenantId, 'receipt', 'receipt', $profile['receipt_prefix'] ?: 'RC', [
            'booking_id' => (int) $booking['id'], 'payment_id' => $paymentId, 'related_id' => $inv['id'] ?? null, 'seller' => $this->seller($profile),
            'buyer' => $inv ? json_decode($inv['buyer'], true) : $this->buyer($tenantId, $booking, []), 'lines' => ['items' => [], 'payment' => [
                'label' => (string) $pay['label'], 'mode' => (string) ($pay['mode'] ?: 'Other'), 'reference' => (string) ($pay['reference'] ?? ''), 'paid_at' => $pay['paid_at'],
                'booking_total' => (int) $booking['total_amount'], 'received_total' => (int) $booking['paid_amount'], 'invoice_number' => $inv['number'] ?? null, 'tcs' => (int) $booking['tcs_amount']]],
            'place_of_supply' => null, 'supply_type' => 'none', 'sac' => null, 'taxable_value' => 0, 'gst_rate' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'tcs_rate' => 0, 'tcs' => 0,
            'total' => (int) $pay['amount'], 'booking_ref' => $booking['booking_ref'], 'reason' => null,
        ], $profile, $userId);
    }

    // ---- reading ----------------------------------------------------------------------------------------------

    /** The booking's live invoice: issued tax invoice / bill of supply that is not fully credited. */
    public function activeInvoice(int $tenantId, int $bookingId): ?array
    {
        $rows = (new InvoiceModel())->setTenant($tenantId)->where('booking_id', $bookingId)->whereIn('doc_type', ['tax_invoice', 'bill_of_supply'])->where('status', 'issued')->orderBy('id', 'DESC')->findAll();
        foreach ($rows as $r) {
            $credited = (int) (db_connect()->query("SELECT COALESCE(SUM(taxable_value),0) t FROM invoices WHERE tenant_id = ? AND related_id = ? AND doc_type = 'credit_note' AND status = 'issued'", [$tenantId, $r['id']])->getRowArray()['t'] ?? 0);
            if ($credited < (int) $r['taxable_value']) { return $r; }
        }
        return null;
    }

    /** PDF bytes of an issued document, verified against the checksum recorded at issue. */
    public function pdf(array $inv): string
    {
        $path = WRITEPATH . ltrim((string) $inv['pdf_path'], '/');
        if (! is_file($path)) { throw new \RuntimeException('The stored PDF for ' . $inv['number'] . ' is missing. Restore it from backup — it must never be regenerated.'); }
        $bytes = (string) file_get_contents($path);
        if (hash('sha256', $bytes) !== $inv['pdf_sha256']) { throw new \RuntimeException('The stored PDF for ' . $inv['number'] . ' failed its integrity check and was not served.'); }
        return $bytes;
    }

    // ---- internals ----------------------------------------------------------------------------------------------

    /** Folder (relative to writable/) holding issued PDFs. Override with INVOICE_STORAGE_DIR — tests MUST, so they never touch real documents. */
    public static function storageDir(): string
    {
        $d = trim((string) env('INVOICE_STORAGE_DIR', 'uploads/invoices'), '/');
        if ($d === '' || str_contains($d, '..')) { throw new \LogicException('INVOICE_STORAGE_DIR must be a folder inside writable/.'); }
        return $d;
    }

    /** Immutable snapshot of the issuing entity. */
    private function seller(array $p): array
    {
        return ['legal_name' => $p['legal_name'], 'trade_name' => $p['trade_name'], 'gstin' => $p['gstin'], 'pan' => $p['pan'], 'address' => DocFormat::addressLines($p),
            'state_code' => $p['state_code'], 'state_name' => Gst::STATES[$p['state_code']] ?? '', 'phone' => DocFormat::phone((string) $p['phone']), 'email' => $p['email'], 'signatory' => $p['signatory'],
            'bank' => ['name' => $p['bank_name'], 'account_name' => $p['bank_account_name'], 'account_no' => $p['bank_account_no'], 'ifsc' => $p['bank_ifsc'], 'upi' => $p['upi_id']],
            'terms' => $p['invoice_terms'], 'logo_url' => $p['logo_url'], 'brand_color' => $p['brand_color']];
    }

    private function buyer(int $tenantId, array $booking, array $in): array
    {
        $c = $booking['contact_id'] ? (new ContactModel())->setTenant($tenantId)->find((int) $booking['contact_id']) : null;
        $name = trim((string) ($in['name'] ?? ($c['name'] ?? '')));
        if ($name === '') { throw new \InvalidArgumentException('Enter the customer\'s name for the invoice.'); }
        $gstin = strtoupper(trim((string) ($in['gstin'] ?? '')));
        if ($gstin !== '' && ! Gst::validGstin($gstin)) { throw new \InvalidArgumentException('The customer\'s GSTIN is not valid — check every character.'); }
        $state = $gstin !== '' ? Gst::stateOfGstin($gstin) : Gst::stateCode($in['state'] ?? ($c['state'] ?? null));
        if (! empty($in['state']) && $state === null) { throw new \InvalidArgumentException('Could not recognise the customer\'s state. Pick it from the list.'); }
        if ($gstin !== '' && ! empty($in['state']) && Gst::stateCode($in['state']) !== null && Gst::stateCode($in['state']) !== $state) { throw new \InvalidArgumentException('The customer\'s state does not match the first two digits of their GSTIN.'); }
        $pin = trim((string) ($in['pincode'] ?? ''));
        if ($pin !== '' && ! preg_match('/^\d{6}$/', $pin)) { throw new \InvalidArgumentException('PIN code must be 6 digits.'); }
        $b = ['name' => $name, 'gstin' => $gstin ?: null, 'state_code' => $state, 'state_name' => $state ? (Gst::STATES[$state] ?? '') : '', 'address_line1' => trim((string) ($in['address_line1'] ?? '')),
            'address_line2' => trim((string) ($in['address_line2'] ?? '')), 'city' => trim((string) ($in['city'] ?? ($c['city'] ?? ''))), 'pincode' => $pin,
            'phone' => DocFormat::phone((string) ($in['phone'] ?? ($c['wa_number'] ?? ''))), 'email' => trim((string) ($in['email'] ?? ($c['email'] ?? '')))];
        $b['address'] = DocFormat::addressLines($b);
        return $b;
    }

    /** One transaction: lock+increment the series, render, write the file, insert. @return array the stored row */
    private function insertDocument(int $tenantId, string $docType, string $series, string $prefix, array $f, array $profile, ?int $userId): array
    {
        $db = db_connect();
        $date = $this->today();
        $fy = Gst::fy($date);
        $path = null;
        $db->transStart();
        try {
            $db->query('INSERT INTO doc_sequences (tenant_id, doc_type, fy, last_seq) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE id = id', [$tenantId, $series, $fy]);
            $row = $db->query('SELECT id, last_seq FROM doc_sequences WHERE tenant_id = ? AND doc_type = ? AND fy = ? FOR UPDATE', [$tenantId, $series, $fy])->getRowArray();
            $seq = (int) $row['last_seq'] + 1;
            $db->query('UPDATE doc_sequences SET last_seq = ? WHERE id = ?', [$seq, $row['id']]);
            $number = Gst::invoiceNumber($prefix, $fy, $seq);

            $doc = $f + ['doc_type' => $docType, 'number' => $number, 'fy' => $fy, 'seq' => $seq, 'issue_date' => $date];
            // E-invoicing (B2B tax invoices + credit notes, when the tenant switched it on): register with the IRP INSIDE this transaction.
            // A rejection / outage rolls the number back, so no invoice and no number exists without a valid IRN.
            $ei = (new \App\Services\Einvoice\EinvoiceService(null, $this->now))->prepare($tenantId, $doc, $profile, $f['original'] ?? null);
            if ($ei) { $doc['einvoice'] = $ei; }
            $pdf = $this->renderPdf($docType, $doc, $profile);
            $rel = self::storageDir() . '/' . $tenantId . '/' . $fy . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $number) . '.pdf';
            $path = WRITEPATH . $rel;
            if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0750, true)) { throw new \RuntimeException('Could not create the invoice storage folder.'); }
            if (file_put_contents($path, $pdf, LOCK_EX) === false) { throw new \RuntimeException('Could not store the invoice PDF.'); }

            $db->table('invoices')->insert([
                'tenant_id' => $tenantId, 'booking_id' => $f['booking_id'], 'payment_id' => $f['payment_id'] ?? null, 'related_id' => $f['related_id'] ?? null, 'doc_type' => $docType,
                'number' => $number, 'fy' => $fy, 'seq' => $seq, 'issue_date' => $date, 'status' => 'issued',
                'seller' => json_encode($f['seller'], JSON_UNESCAPED_UNICODE), 'buyer' => json_encode($f['buyer'], JSON_UNESCAPED_UNICODE),
                'lines' => json_encode($f['lines'] + ['booking_ref' => $f['booking_ref']], JSON_UNESCAPED_UNICODE), 'place_of_supply' => $f['place_of_supply'], 'supply_type' => $f['supply_type'],
                'sac' => $f['sac'], 'taxable_value' => $f['taxable_value'], 'gst_rate' => $f['gst_rate'], 'cgst' => $f['cgst'], 'sgst' => $f['sgst'], 'igst' => $f['igst'],
                'tcs_rate' => $f['tcs_rate'], 'tcs' => $f['tcs'], 'total' => $f['total'], 'reason' => $f['reason'], 'pdf_path' => $rel, 'pdf_sha256' => hash('sha256', $pdf),
                'share_token' => bin2hex(random_bytes(16)), 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s', $this->now ?? time()),
            ] + ($ei ? ['einvoice_irn' => $ei['irn'], 'einvoice_status' => 'generated', 'einvoice_ack_no' => $ei['ack_no'], 'einvoice_ack_dt' => $ei['ack_dt'], 'einvoice_qr' => $ei['signed_qr']] : []));
            $id = (int) $db->insertID();
            $db->transComplete();
            if (! $db->transStatus()) { throw new \RuntimeException('Could not save the document.'); }
        } catch (\Throwable $e) {
            $db->transRollback();
            if ($path && is_file($path)) { @unlink($path); }          // no orphan file for a number that was rolled back
            throw $e;
        }
        AuditLogger::log('invoice.issue', 'invoice', $id, null, ['number' => $number, 'type' => $docType, 'total' => $f['total']], $tenantId, $userId);
        return (new InvoiceModel())->setTenant($tenantId)->find($id);
    }

    private function renderPdf(string $docType, array $d, array $profile): string
    {
        $brand = ['color' => $profile['brand_color'] ?: '#0a6cc4', 'logo' => $this->images ? SafeImage::dataUri($profile['logo_url'], 500) : null, 'name' => $profile['trade_name'] ?: $profile['legal_name']];
        if ($docType === 'receipt') {
            $p = $d['lines']['payment'];
            $view = ['brand' => $brand, 'seller' => $d['seller'], 'buyer' => $d['buyer'], 'doc' => ['number' => $d['number'], 'date' => DocFormat::date($d['issue_date']), 'total' => $d['total'], 'words' => AmountInWords::paise($d['total']),
                'towards' => $p['label'] . ' — tour package booking', 'booking_ref' => $d['booking_ref'], 'mode' => strtoupper((string) $p['mode']) === 'UPI' ? 'UPI' : ucfirst((string) $p['mode']), 'reference' => $p['reference'],
                'paid_on' => DocFormat::date($p['paid_at']), 'invoice_number' => $p['invoice_number'], 'booking_total' => $p['booking_total'], 'received_total' => $p['received_total'],
                'tcs_note' => $p['tcs'] > 0 ? 'The booking total includes TCS collected as per the Income-tax Act.' : '']];
            return PdfRenderer::render(view('pdf/receipt', $view), $d['number'] . ' · ' . $d['seller']['legal_name'], 'Payment receipt ' . $d['number']);
        }

        $isCn = $docType === 'credit_note';
        $o = $d['original'] ?? null;
        $rate = (float) $d['gst_rate'];
        $declaration = ($docType === 'bill_of_supply' || ($o['doc_type'] ?? null) === 'bill_of_supply') ? '' : ($rate > 0 && $rate <= 5.0
            ? "GST charged at {$rate}% for tour operator services without input tax credit on goods or services used to provide this supply (other than input tax credit on tour operator services in the same line of business), as per the applicable GST notification."
            : "GST charged at {$rate}% on tour operator services, with input tax credit as applicable.");
        $bank = ['has' => false, 'rows' => [], 'qr' => null, 'qr_amount' => 0];
        $b = $d['seller']['bank'] ?? [];
        foreach ([['Account name', $b['account_name'] ?? null], ['Bank', $b['name'] ?? null], ['Account no.', $b['account_no'] ?? null], ['IFSC', $b['ifsc'] ?? null], ['UPI ID', $b['upi'] ?? null]] as [$k, $v]) {
            if (! empty($v)) { $bank['rows'][] = [$k, $v]; $bank['has'] = true; }
        }
        $balance = max(0, $d['total'] - (int) ($d['lines']['paid_at_issue'] ?? 0));
        if (! $isCn && ! empty($b['upi']) && $balance > 0) {
            $bank['qr'] = Qr::dataUri(Gst::upiUri((string) $b['upi'], (string) $d['seller']['legal_name'], $balance, $d['number']), 4);
            $bank['qr_amount'] = $balance;
        }
        $view = ['brand' => $brand, 'seller' => $d['seller'], 'buyer' => $d['buyer'], 'lines' => $d['lines']['items'], 'declaration' => $declaration, 'bank' => $bank, 'terms' => $d['seller']['terms'] ?? '',
            'doc' => ['doc_type' => $docType, 'status' => 'issued', 'number' => $d['number'], 'date' => DocFormat::date($d['issue_date']), 'booking_ref' => $d['booking_ref'], 'place_of_supply' => $d['place_of_supply'],
                'place_name' => Gst::STATES[$d['place_of_supply'] ?? ''] ?? '', 'supply_type' => $d['supply_type'], 'sac' => $d['sac'], 'taxable_value' => $d['taxable_value'], 'gst_rate' => $d['gst_rate'],
                'cgst' => $d['cgst'], 'sgst' => $d['sgst'], 'igst' => $d['igst'], 'tcs_rate' => $d['tcs_rate'], 'tcs' => $d['tcs'], 'total' => $d['total'], 'words' => AmountInWords::paise($d['total']), 'reason' => $d['reason'],
                'einvoice' => ! empty($d['einvoice']) ? ['irn' => $d['einvoice']['irn'], 'ack_no' => $d['einvoice']['ack_no'], 'ack_dt' => DocFormat::date(substr($d['einvoice']['ack_dt'], 0, 10)), 'qr' => Qr::dataUri($d['einvoice']['signed_qr'], 3)] : null,
                'original_number' => $o['number'] ?? null, 'original_doc_type' => $o['doc_type'] ?? null, 'original_date' => isset($o['issue_date']) ? DocFormat::date($o['issue_date']) : null, 'paid_at_issue' => $isCn ? null : ($d['lines']['paid_at_issue'] ?? 0)]];
        return PdfRenderer::render(view('pdf/invoice', $view), $d['number'] . ' · ' . $d['seller']['legal_name'] . (empty($d['seller']['gstin']) ? '' : ' · GSTIN ' . $d['seller']['gstin']), ($isCn ? 'Credit note ' : 'Invoice ') . $d['number']);
    }
}
