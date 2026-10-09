<?php

declare(strict_types=1);

namespace App\Services\Einvoice;

use App\Services\Billing\Docs\Gst;

/**
 * Local simulator of the IRP for development and demos (only usable when EINVOICE_MOCK_MODE=true — a simulated IRN must NEVER end up on a real
 * invoice). It enforces the behaviours that matter to our code: schema/arithmetic rejection, duplicate detection (error 2150 with the existing IRN),
 * the 24-hour cancellation window and "already cancelled". State persists in writable/einvoice-mock-{tenant}.json. It is NOT proof against the
 * real IRP; the real one signs the QR with NIC's private key.
 */
final class MockIrpClient implements IrpClient
{
    private string $file;
    private array $db;

    public function __construct(int $tenantId, private readonly ?int $now = null)
    {
        $this->file = WRITEPATH . "einvoice-mock-{$tenantId}.json";
        $this->db = is_file($this->file) ? (json_decode((string) file_get_contents($this->file), true) ?: []) : [];
        $this->db += ['seq' => 1_000_000, 'docs' => []];
    }

    public static function enabled(): bool { return filter_var(env('EINVOICE_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN); }
    private function ts(): int { return $this->now ?? time(); }
    private function save(): void { file_put_contents($this->file, json_encode($this->db)); }
    private static function key(string $gstin, string $type, string $no): string { return $gstin . '|' . $type . '|' . strtoupper($no); }

    public function generate(array $p): array
    {
        $seller = (string) ($p['SellerDtls']['Gstin'] ?? '');
        if (! Gst::validGstin($seller)) { throw new EinvoiceException('Invalid supplier GSTIN.', EinvoiceException::REJECTED, '2150'); }
        if (($p['Version'] ?? '') !== '1.1') { throw new EinvoiceException('Invalid schema version.', EinvoiceException::REJECTED, '2000'); }
        if ($bad = EinvoiceValidator::check($p, date('Y-m-d', $this->ts()))) { throw new EinvoiceException('Schema validation failed: ' . implode(' ', array_slice($bad, 0, 3)), EinvoiceException::REJECTED, '2172'); }
        $type = (string) $p['DocDtls']['Typ']; $no = (string) $p['DocDtls']['No'];
        $k = self::key($seller, $type, $no);
        if (isset($this->db['docs'][$k])) {
            $x = $this->db['docs'][$k];
            throw new EinvoiceException('Duplicate IRN: this document was already registered.', EinvoiceException::DUPLICATE, '2150', ['existing' => ['irn' => $x['irn'], 'ack_no' => $x['ack_no'], 'ack_dt' => $x['ack_dt'], 'signed_qr' => $x['signed_qr']]]);
        }
        $irn = hash('sha256', $seller . '|' . $type . '|' . strtoupper($no));
        $ackNo = (string) (++$this->db['seq']);
        $ackDt = date('Y-m-d H:i:s', $this->ts());
        $qr = 'MOCK.' . rtrim(strtr(base64_encode((string) json_encode(EinvoiceBuilder::qrFields($p, $irn, $ackDt))), '+/', '-_'), '=') . '.UNSIGNED-DEMO';
        $this->db['docs'][$k] = ['irn' => $irn, 'ack_no' => $ackNo, 'ack_dt' => $ackDt, 'signed_qr' => $qr, 'status' => 'ACT', 'doc_date' => $p['DocDtls']['Dt']];
        $this->save();
        return ['irn' => $irn, 'ack_no' => $ackNo, 'ack_dt' => $ackDt, 'signed_qr' => $qr];
    }

    public function cancel(string $irn, int $reasonCode, string $remark): array
    {
        if ($reasonCode < 1 || $reasonCode > 4) { throw new EinvoiceException('Invalid cancel reason code.', EinvoiceException::REJECTED, '2204'); }
        foreach ($this->db['docs'] as $k => $x) {
            if ($x['irn'] !== $irn) { continue; }
            if ($x['status'] === 'CNL') { throw new EinvoiceException('The IRN is already cancelled.', EinvoiceException::REJECTED, '2270'); }
            if ($this->ts() - strtotime($x['ack_dt']) > 86400) { throw new EinvoiceException('The cancellation window has expired. Cancel it on the GST e-invoice portal or issue a credit note.', EinvoiceException::WINDOW, '2162'); }
            $this->db['docs'][$k]['status'] = 'CNL'; $this->save();
            return ['irn' => $irn, 'cancel_dt' => date('Y-m-d H:i:s', $this->ts())];
        }
        throw new EinvoiceException('The IRN was not found.', EinvoiceException::REJECTED, '2150');
    }

    public function findByDocument(string $docType, string $docNo, string $docDate): ?array
    {
        foreach ($this->db['docs'] as $k => $x) {
            if (str_ends_with($k, '|' . $docType . '|' . strtoupper($docNo)) && $x['doc_date'] === $docDate) { return array_intersect_key($x, array_flip(['irn', 'ack_no', 'ack_dt', 'signed_qr'])); }
        }
        return null;
    }
}
