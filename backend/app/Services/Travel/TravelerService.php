<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\TravelerModel;
use App\Services\Crm\AuditLogger;

/**
 * Edit, remove and (audited) read a traveller's passport number. Passport numbers are sensitive (DPDP): encrypted at rest, never in a list answer,
 * and every time one is shown to a staff member it is written to the audit log.
 * Exceptions: InvalidArgumentException = bad input (422), OutOfBoundsException = not found (404), DomainException = not allowed now (409).
 */
final class TravelerService
{
    public const PAX = ['adult', 'child', 'infant'];
    public const GENDER = ['male', 'female', 'other'];
    public const VISA = ['not_required', 'pending', 'applied', 'approved', 'rejected'];

    /** @return array<string,mixed> the traveller without the encrypted number (has_passport instead) */
    public static function safe(array $t): array
    {
        $t['has_passport'] = ! empty($t['passport_no_enc']);
        unset($t['passport_no_enc']);
        return $t;
    }

    private function find(int $tenantId, int $id): array
    {
        $t = (new TravelerModel())->setTenant($tenantId)->find($id);
        if (! $t) { throw new \OutOfBoundsException('Traveller not found.'); }
        return $t;
    }

    private function date(mixed $v, string $label): ?string
    {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        if (! $d || $d->format('Y-m-d') !== $v) { throw new \InvalidArgumentException("{$label}: use a real date (YYYY-MM-DD)."); }
        return $v;
    }

    /** @param array<string,mixed> $in */
    public function update(int $tenantId, int $id, array $in, ?int $userId = null): array
    {
        $t = $this->find($tenantId, $id);
        $d = [];
        if (array_key_exists('full_name', $in)) {
            $n = trim((string) $in['full_name']);
            if ($n === '' || mb_strlen($n) > 200) { throw new \InvalidArgumentException('Enter the traveller\'s name (up to 200 characters).'); }
            $d['full_name'] = $n;
        }
        foreach (['pax_type' => self::PAX, 'gender' => self::GENDER, 'visa_status' => self::VISA] as $k => $allowed) {
            if (array_key_exists($k, $in)) {
                $v = (string) $in[$k];
                if ($v === '' && $k === 'gender') { $d[$k] = null; continue; }
                if (! in_array($v, $allowed, true)) { throw new \InvalidArgumentException("Choose a valid {$k}: " . implode(', ', $allowed) . '.'); }
                $d[$k] = $v;
            }
        }
        if (array_key_exists('dob', $in)) { $d['dob'] = $this->date($in['dob'], 'Date of birth'); }
        if (array_key_exists('passport_expiry', $in)) { $d['passport_expiry'] = $this->date($in['passport_expiry'], 'Passport expiry'); }
        foreach (['nationality' => 60, 'meal_pref' => 60] as $k => $max) {
            if (array_key_exists($k, $in)) { $v = trim((string) $in[$k]); $d[$k] = $v === '' ? null : mb_substr($v, 0, $max); }
        }
        if (array_key_exists('passport_no', $in) && trim((string) $in['passport_no']) !== '') {
            $p = strtoupper(preg_replace('/\s+/', '', (string) $in['passport_no']));
            if (! preg_match('/^[A-Z0-9]{5,20}$/', $p)) { throw new \InvalidArgumentException('A passport number is 5 to 20 letters and digits.'); }
            $d['passport_no_enc'] = base64_encode(service('encrypter')->encrypt($p));
        }
        if ($d === []) { return self::safe($t); }
        (new TravelerModel())->setTenant($tenantId)->update($id, $d);
        try { (new ChecklistService())->generate($tenantId, (int) $t['booking_id']); } catch (\Throwable $e) { log_message('warning', 'checklist: ' . $e->getMessage()); }
        AuditLogger::log('traveler.update', 'traveler', $id, null, ['fields' => array_values(array_diff(array_keys($d), ['passport_no_enc'])), 'passport_changed' => isset($d['passport_no_enc'])], $tenantId, $userId);
        return self::safe((new TravelerModel())->setTenant($tenantId)->find($id));
    }

    /** A traveller with documents already on file cannot be removed here (those files are passports: handle them deliberately). */
    public function delete(int $tenantId, int $id, ?int $userId = null): void
    {
        $t = $this->find($tenantId, $id);
        $db = db_connect();
        $held = (int) $db->table('booking_checklist_items')->where('tenant_id', $tenantId)->where('traveler_id', $id)->whereIn('status', ['uploaded', 'received'])->countAllResults();
        if ($held > 0) { throw new \DomainException('This traveller already has documents on file, so they cannot be removed here.'); }
        $db->transStart();
        $db->table('booking_checklist_items')->where('tenant_id', $tenantId)->where('traveler_id', $id)->delete();
        (new TravelerModel())->setTenant($tenantId)->delete($id);
        $db->transComplete();
        AuditLogger::log('traveler.delete', 'traveler', $id, ['full_name' => $t['full_name']], null, $tenantId, $userId);
    }

    /** The decrypted passport number, for staff who need to book flights. Always audited. */
    public function passport(int $tenantId, int $id, ?int $userId = null): string
    {
        $t = $this->find($tenantId, $id);
        if (empty($t['passport_no_enc'])) { throw new \DomainException('No passport number has been saved for this traveller.'); }
        $p = service('encrypter')->decrypt((string) base64_decode((string) $t['passport_no_enc'], true));
        AuditLogger::log('traveler.passport_view', 'traveler', $id, null, null, $tenantId, $userId);
        return $p;
    }
}
