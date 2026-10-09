<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Issued billing documents are IMMUTABLE legal records: reads only through this model.
 * Inserts happen in InvoiceService (inside the numbering transaction); there is deliberately no update/delete path.
 */
class InvoiceModel extends BaseModel
{
    protected $table      = 'invoices';
    protected $primaryKey = 'id';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $allowedFields  = ['tenant_id', 'booking_id', 'payment_id', 'related_id', 'doc_type', 'number', 'fy', 'seq', 'issue_date', 'status', 'seller', 'buyer', 'lines',
        'place_of_supply', 'supply_type', 'sac', 'taxable_value', 'gst_rate', 'cgst', 'sgst', 'igst', 'tcs_rate', 'tcs', 'total', 'reason', 'pdf_path', 'pdf_sha256', 'share_token', 'einvoice_irn', 'einvoice_status', 'einvoice_ack_no', 'einvoice_ack_dt', 'einvoice_qr', 'einvoice_cancelled_at', 'einvoice_cancel_reason', 'created_by', 'created_at'];

    /** Blocks accidental edits of a legal record (only the cancel flag may ever change — and only via cancel()). */
    protected $beforeUpdate = ['refuseEdit'];

    protected function refuseEdit(array $data): array
    {
        $allowed = ['status', 'tenant_id', 'einvoice_status', 'einvoice_cancelled_at', 'einvoice_cancel_reason'];   // tenant_id is injected by BaseModel on every update; the rest is the cancel path only
        if (array_diff(array_keys($data['data'] ?? []), $allowed) !== []) {
            throw new \LogicException('Issued invoices cannot be edited. Issue a credit note instead.');
        }
        return $data;
    }
}
