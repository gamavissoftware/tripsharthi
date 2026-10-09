<?php

declare(strict_types=1);

namespace App\Services\Einvoice;

/** What TravelPilot needs from an Invoice Registration Portal connection (directly or through a GSP). */
interface IrpClient
{
    /**
     * Register one invoice. @param array $payload INV-01 JSON
     * @return array{irn:string,ack_no:string,ack_dt:string,signed_qr:string}  ack_dt = 'Y-m-d H:i:s'
     * @throws EinvoiceException REJECTED / DUPLICATE (extra['existing'] = the already-registered IRN data) / TRANSIENT / AUTH
     */
    public function generate(array $payload): array;

    /** @param int $reasonCode 1 duplicate, 2 data-entry mistake, 3 order cancelled, 4 other. @return array{irn:string,cancel_dt:string} */
    public function cancel(string $irn, int $reasonCode, string $remark): array;

    /** Look an already-registered invoice up by its document details (used to recover from a duplicate). @return array{irn:string,ack_no:string,ack_dt:string,signed_qr:string}|null */
    public function findByDocument(string $docType, string $docNo, string $docDate): ?array;
}
