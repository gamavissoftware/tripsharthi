<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\BookingModel;
use App\Services\Billing\Docs\BusinessProfileService;
use App\Services\Crm\AuditLogger;
use App\Services\Crm\NotificationService;

/**
 * Customer self-service portal. The 32-hex portal_token on a booking is the ONLY credential, so everything it returns is
 * customer-safe (never cost/margin/supplier), every id a customer sends is re-checked against that booking, and uploads are
 * validated by content (not by the name the browser claims), size-capped, rate-limited and ENCRYPTED at rest (passports are sensitive, DPDP).
 */
final class PortalService
{
    // Exceptions: OutOfBoundsException = the link / an id does not belong here (404); InvalidArgumentException = bad input (422); DomainException = not allowed now (409).
    public const MAX_BYTES = 5_242_880;
    public const MAX_PER_ITEM = 5;
    public const MAX_PER_BOOKING_PER_DAY = 40;
    private const TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    public function __construct(private readonly ?int $now = null) {}
    private function stamp(): string { return date('Y-m-d H:i:s', $this->now ?? time()); }
    public static function storageDir(): string { return trim((string) (getenv('PORTAL_STORAGE_DIR') ?: 'uploads/portal'), '/'); }

    // ---- staff side -------------------------------------------------------------------------------------------

    /** The booking's portal token, created on first use. */
    public function ensureToken(int $tenantId, int $bookingId): string
    {
        $b = (new BookingModel())->setTenant($tenantId)->find($bookingId);
        if (! $b) { throw new \InvalidArgumentException('Booking not found.'); }
        if (! empty($b['portal_token'])) { return (string) $b['portal_token']; }
        $token = bin2hex(random_bytes(16));
        db_connect()->table('bookings')->where('tenant_id', $tenantId)->where('id', $bookingId)->update(['portal_token' => $token]);
        return $token;
    }

    /** Invalidate the old link (e.g. it was forwarded) and issue a new one. */
    public function rotateToken(int $tenantId, int $bookingId, ?int $userId = null): string
    {
        $this->ensureToken($tenantId, $bookingId);
        $token = bin2hex(random_bytes(16));
        db_connect()->table('bookings')->where('tenant_id', $tenantId)->where('id', $bookingId)->update(['portal_token' => $token]);
        AuditLogger::log('portal.rotate', 'booking', $bookingId, null, null, $tenantId, $userId);
        return $token;
    }

    public function uploadsForItem(int $tenantId, int $itemId): array
    {
        $rows = db_connect()->table('portal_uploads')->where('tenant_id', $tenantId)->where('checklist_item_id', $itemId)->orderBy('id', 'DESC')->get()->getResultArray();
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['original_name'], 'mime' => $r['mime'], 'size' => (int) $r['size'], 'status' => $r['status'], 'reject_reason' => $r['reject_reason'], 'uploaded_at' => $r['created_at']], $rows);
    }

    /** @return array{bytes:string,mime:string,name:string} decrypted and integrity-checked */
    public function readUpload(int $tenantId, int $uploadId): array
    {
        $r = db_connect()->table('portal_uploads')->where('tenant_id', $tenantId)->where('id', $uploadId)->get()->getRowArray();
        if (! $r) { throw new \InvalidArgumentException('File not found.'); }
        $path = WRITEPATH . $r['path'];
        if (! is_file($path)) { throw new \RuntimeException('The stored file is missing.'); }
        $plain = service('encrypter')->decrypt((string) file_get_contents($path));
        if (hash('sha256', $plain) !== $r['sha256']) { throw new \RuntimeException('The stored file failed its integrity check and was not served.'); }
        return ['bytes' => $plain, 'mime' => $r['mime'], 'name' => $r['original_name']];
    }

    /** Accept (item becomes received) or reject with a reason the customer sees (item goes back to pending). */
    public function review(int $tenantId, int $uploadId, bool $accept, ?string $reason, ?int $userId): void
    {
        $db = db_connect();
        $u = $db->table('portal_uploads')->where('tenant_id', $tenantId)->where('id', $uploadId)->get()->getRowArray();
        if (! $u) { throw new \InvalidArgumentException('File not found.'); }
        if (! $accept && trim((string) $reason) === '') { throw new \InvalidArgumentException('Tell the customer why it was rejected (e.g. "photo is blurry").'); }
        $db->transStart();
        $db->table('portal_uploads')->where('id', $uploadId)->update(['status' => $accept ? 'accepted' : 'rejected', 'reject_reason' => $accept ? null : mb_substr(trim((string) $reason), 0, 255), 'reviewed_by' => $userId, 'reviewed_at' => $this->stamp()]);
        $db->table('booking_checklist_items')->where('tenant_id', $tenantId)->where('id', $u['checklist_item_id'])->update(
            $accept ? ['status' => 'received', 'received_at' => $this->stamp(), 'updated_by' => $userId, 'updated_at' => $this->stamp()]
                    : ['status' => 'pending', 'notes' => mb_substr('Rejected: ' . trim((string) $reason), 0, 255), 'updated_by' => $userId, 'updated_at' => $this->stamp()]);
        $db->transComplete();
        AuditLogger::log($accept ? 'portal.accept' : 'portal.reject', 'portal_upload', $uploadId, null, null, $tenantId, $userId);
    }

    // ---- customer side ------------------------------------------------------------------------------------------

    /** @return array{tenant_id:int,booking:array} */
    private function resolve(string $token): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) { throw new \OutOfBoundsException('This link is not valid.'); }
        $b = (new BookingModel())->withoutTenantScope()->where('portal_token', $token)->first();
        if (! $b) { throw new \OutOfBoundsException('This link is not valid.'); }
        return ['tenant_id' => (int) $b['tenant_id'], 'booking' => $b];
    }

    public function view(string $token): array
    {
        ['tenant_id' => $tid, 'booking' => $b] = $this->resolve($token);
        $db = db_connect();
        $profile = (new BusinessProfileService())->get($tid);
        $payments = $db->table('booking_payments')->where('tenant_id', $tid)->where('booking_id', $b['id'])->where('deleted_at', null)->orderBy('due_date')->get()->getResultArray();
        $docs = $db->table('invoices')->where('tenant_id', $tid)->where('booking_id', $b['id'])->where('status', 'issued')->orderBy('id')->get()->getResultArray();
        $svcs = $db->table('booking_services')->where('tenant_id', $tid)->where('booking_id', $b['id'])->where('status', 'confirmed')->where('deleted_at', null)->get()->getResultArray();
        $items = $db->table('booking_checklist_items')->where('tenant_id', $tid)->where('booking_id', $b['id'])->orderBy('traveler_id')->orderBy('id')->get()->getResultArray();
        $itinerary = $b['itinerary_id'] ? (new ItineraryService())->full($tid, (int) $b['itinerary_id'], true) : null;
        if ($itinerary) { unset($itinerary['tenant_id']); }
        $cancelled = $b['status'] === 'cancelled';

        return [
            'agency' => ['name' => $profile['trade_name'] ?: $profile['legal_name'] ?: '', 'phone' => $profile['phone'] ?? '', 'email' => $profile['email'] ?? '', 'logo_url' => $profile['logo_url'] ?? null, 'color' => $profile['brand_color'] ?? '#0a6cc4'],
            'booking' => ['ref' => $b['booking_ref'], 'title' => $b['title'], 'status' => $b['status'], 'travel_start' => $b['travel_start'], 'travel_end' => $b['travel_end'], 'total' => (int) $b['total_amount'], 'paid' => (int) $b['paid_amount'], 'due' => max(0, (int) $b['total_amount'] - (int) $b['paid_amount'])],
            'itinerary' => $itinerary,
            'payments' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'label' => $p['label'], 'due_date' => $p['due_date'], 'amount' => (int) $p['amount'], 'status' => $p['status'], 'payable' => ! $cancelled && in_array($p['status'], ['pending', 'overdue'], true)], $payments),
            'documents' => array_merge(
                array_map(static fn ($d) => ['kind' => $d['doc_type'], 'title' => $d['number'], 'url' => '/api/v1/public/invoices/' . $d['share_token'] . '/pdf'], $docs),
                array_map(static fn ($s) => ['kind' => 'voucher', 'title' => $s['title'], 'url' => '/api/v1/public/vouchers/' . $s['voucher_token'] . '/pdf'], array_values(array_filter($svcs, static fn ($s) => ! empty($s['voucher_token']))))),
            'checklist' => array_map(function ($i) use ($db, $tid) {
                $last = $db->table('portal_uploads')->where('tenant_id', $tid)->where('checklist_item_id', $i['id'])->orderBy('id', 'DESC')->get(1)->getRowArray();
                return ['id' => (int) $i['id'], 'label' => $i['label'], 'required' => (int) $i['required'], 'status' => $i['status'], 'due_date' => $i['due_date'], 'can_upload' => ! in_array($i['status'], ['received', 'not_applicable'], true),
                    'rejected_reason' => ($last['status'] ?? null) === 'rejected' ? $last['reject_reason'] : null];
            }, $items),
            'progress' => ChecklistPlanner::progress(array_map(static fn ($i) => ['required' => $i['required'], 'status' => $i['status']], $items)),
        ];
    }

    /** Payment link for one instalment of THIS booking. @return array{url:string} */
    public function payLink(string $token, int $paymentId): array
    {
        ['tenant_id' => $tid, 'booking' => $b] = $this->resolve($token);
        $p = db_connect()->table('booking_payments')->where('tenant_id', $tid)->where('id', $paymentId)->where('booking_id', $b['id'])->get()->getRowArray();
        if (! $p) { throw new \OutOfBoundsException('That payment is not part of this booking.'); }
        try { $link = (new InstalmentLinkService())->ensure($tid, $paymentId); }
        catch (\DomainException $e) { throw $e; }
        catch (\RuntimeException $e) { throw new \DomainException('Online payment is not available right now. Please contact ' . ((new BusinessProfileService())->get($tid)['trade_name'] ?: 'your travel agent') . ' to pay.'); }
        return ['url' => (string) $link['short_url']];
    }

    /**
     * @param array{tmp_name:string,name:string,size:int} $file
     * @return array{status:string}
     */
    public function upload(string $token, int $itemId, array $file): array
    {
        ['tenant_id' => $tid, 'booking' => $b] = $this->resolve($token);
        $db = db_connect();
        $item = $db->table('booking_checklist_items')->where('tenant_id', $tid)->where('id', $itemId)->where('booking_id', $b['id'])->get()->getRowArray();
        if (! $item) { throw new \OutOfBoundsException('That item is not part of this booking.'); }
        if (in_array($item['status'], ['received', 'not_applicable'], true)) { throw new \DomainException('This item is already complete.'); }
        if ($b['status'] === 'cancelled') { throw new \DomainException('This booking is cancelled.'); }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES) { throw new \InvalidArgumentException('The file must be under 5 MB.'); }
        $bytes = (string) file_get_contents($file['tmp_name']);
        $ext = $this->sniff($bytes);
        if ($ext === null) { throw new \InvalidArgumentException('Please upload a PDF, JPG or PNG file.'); }
        $perItem = (int) $db->table('portal_uploads')->where('checklist_item_id', $itemId)->countAllResults();
        if ($perItem >= self::MAX_PER_ITEM) { throw new \DomainException('Too many files for this item. Please contact your travel agent.'); }
        $today = (int) $db->query('SELECT COUNT(*) n FROM portal_uploads WHERE tenant_id = ? AND booking_id = ? AND created_at >= ?', [$tid, $b['id'], date('Y-m-d H:i:s', ($this->now ?? time()) - 86400)])->getRowArray()['n'];
        if ($today >= self::MAX_PER_BOOKING_PER_DAY) { throw new \DomainException('Upload limit reached for today. Please try again tomorrow.'); }

        $rel = self::storageDir() . '/' . $tid . '/' . $b['id'] . '/' . bin2hex(random_bytes(16)) . '.enc';
        $path = WRITEPATH . $rel;
        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0750, true)) { throw new \RuntimeException('Could not store the file.'); }
        if (file_put_contents($path, service('encrypter')->encrypt($bytes), LOCK_EX) === false) { throw new \RuntimeException('Could not store the file.'); }
        $safeName = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename((string) $file['name'])) ?: ('upload.' . $ext), 0, 200);
        $db->transStart();
        $db->table('portal_uploads')->insert(['tenant_id' => $tid, 'booking_id' => $b['id'], 'checklist_item_id' => $itemId, 'original_name' => $safeName, 'mime' => self::TYPES[$ext], 'size' => strlen($bytes),
            'path' => $rel, 'sha256' => hash('sha256', $bytes), 'status' => 'pending', 'created_at' => $this->stamp()]);
        $db->table('booking_checklist_items')->where('id', $itemId)->update(['status' => 'uploaded', 'updated_at' => $this->stamp()]);
        $db->transComplete();
        if (! $db->transStatus()) { @unlink($path); throw new \RuntimeException('Could not save the upload.'); }

        $owner = (int) ($b['owner_id'] ?? 0);
        if ($owner > 0) { NotificationService::notify($tid, $owner, 'document_uploaded', "Customer uploaded \"{$item['label']}\" for {$b['booking_ref']} — please review.", '/bookings/' . $b['id']); }
        return ['status' => 'uploaded'];
    }

    /** File type from CONTENT, never from the client's filename or declared type. */
    private function sniff(string $b): ?string
    {
        if (str_starts_with($b, '%PDF-')) { return 'pdf'; }
        if (str_starts_with($b, "\xFF\xD8\xFF")) { return 'jpg'; }
        if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) { return 'png'; }
        return null;
    }
}
