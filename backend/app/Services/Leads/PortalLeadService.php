<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\ActivityModel;
use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Services\Crm\AuditLogger;
use App\Services\Travel\TripService;

/**
 * Enquiries from travel portals and aggregators (TravelTriangle, Thrillophilia, Justdial, IndiaMART, a partner's website…).
 * Two doors: a per-source secret webhook URL, and routing of the portal's notification EMAILS (matched by sender) through the existing
 * inbound-email pipeline. Both end in the same place: LeadFieldExtractor -> contact upsert (source 'portal', tagged with the portal's
 * name, attribution channel = the portal) -> a trip enquiry in the Travel Sales pipeline -> push alert to the owner.
 *
 * Safety: the token is the credential (32 hex, rotatable); a retried delivery of the same lead is ignored (payload hash, 7 days);
 * a lead with no usable phone/email is rejected with the reason; an open enquiry from the same person for the same destination is
 * not duplicated; the raw payload is kept in lead_events for audit and support (personal data - purge per retention policy).
 */
final class PortalLeadService
{
    public const DUPLICATE_DAYS = 7;
    public const OPEN_ENQUIRY_DAYS = 14;
    public function __construct(private readonly ?int $now = null) {}
    private function ts(): int { return $this->now ?? time(); }
    private function stamp(): string { return date('Y-m-d H:i:s', $this->ts()); }
    private function today(): string { return (new \DateTimeImmutable('@' . $this->ts()))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d'); }

    // ---- source management ---------------------------------------------------------------------------------------------

    public function save(int $tenantId, array $in, ?int $id = null, ?int $userId = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) { throw new \InvalidArgumentException('Give the lead source a name (e.g. TravelTriangle).'); }
        $kind = in_array($in['kind'] ?? 'webhook', ['webhook', 'email'], true) ? (string) ($in['kind'] ?? 'webhook') : throw new \InvalidArgumentException('Choose webhook or email.');
        $match = strtolower(trim((string) ($in['email_match'] ?? '')));
        if ($kind === 'email' && ! preg_match('/^(@[a-z0-9.\-]+\.[a-z]{2,}|[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})$/', $match)) { throw new \InvalidArgumentException('Enter the sender the portal emails come from: an address (leads@portal.com) or a whole domain (@portal.com).'); }
        $map = [];
        foreach ((array) ($in['field_map'] ?? []) as $alias => $field) {
            if (! is_string($alias) || trim($alias) === '') { continue; }
            if (! isset(LeadFieldExtractor::ALIASES[$field])) { throw new \InvalidArgumentException("Unknown field '{$field}'. Use one of: " . implode(', ', array_keys(LeadFieldExtractor::ALIASES)) . '.'); }
            $map[mb_substr(trim($alias), 0, 60)] = $field;
        }
        if (count($map) > 40) { throw new \InvalidArgumentException('Too many custom field names (max 40).'); }
        $owner = ! empty($in['owner_id']) ? (int) $in['owner_id'] : null;
        if ($owner && ! db_connect()->table('users')->where('tenant_id', $tenantId)->where('id', $owner)->countAllResults()) { throw new \InvalidArgumentException('That owner is not on your team.'); }
        $row = ['name' => $name, 'kind' => $kind, 'email_match' => $kind === 'email' ? $match : null, 'field_map' => $map ? json_encode($map) : null, 'create_trip' => array_key_exists('create_trip', $in) ? (int) ! empty($in['create_trip']) : 1,
            'owner_id' => $owner, 'enabled' => array_key_exists('enabled', $in) ? (int) ! empty($in['enabled']) : 1, 'updated_at' => $this->stamp()];
        $db = db_connect();
        if ($id === null) {
            $db->table('lead_sources')->insert($row + ['tenant_id' => $tenantId, 'token' => bin2hex(random_bytes(16)), 'created_at' => $this->stamp()]);
            $id = (int) $db->insertID();
        } else {
            $this->row($tenantId, $id);
            $db->table('lead_sources')->where('tenant_id', $tenantId)->where('id', $id)->update($row);
        }
        AuditLogger::log('lead_source.save', 'lead_source', $id, null, ['name' => $name, 'kind' => $kind], $tenantId, $userId);
        return $this->view($this->row($tenantId, $id));
    }

    public function rotateToken(int $tenantId, int $id, ?int $userId = null): array
    {
        $this->row($tenantId, $id);
        db_connect()->table('lead_sources')->where('tenant_id', $tenantId)->where('id', $id)->update(['token' => bin2hex(random_bytes(16)), 'updated_at' => $this->stamp()]);
        AuditLogger::log('lead_source.rotate', 'lead_source', $id, null, null, $tenantId, $userId);
        return $this->view($this->row($tenantId, $id));
    }

    public function delete(int $tenantId, int $id, ?int $userId = null): void
    {
        $this->row($tenantId, $id);
        db_connect()->table('lead_sources')->where('tenant_id', $tenantId)->where('id', $id)->update(['deleted_at' => $this->stamp(), 'enabled' => 0]);
        AuditLogger::log('lead_source.delete', 'lead_source', $id, null, null, $tenantId, $userId);
    }

    private function row(int $tenantId, int $id): array
    {
        $r = db_connect()->table('lead_sources')->where('tenant_id', $tenantId)->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if (! $r) { throw new \InvalidArgumentException('Lead source not found.'); }
        return $r;
    }

    private function view(array $s): array
    {
        return ['id' => (int) $s['id'], 'name' => $s['name'], 'kind' => $s['kind'], 'token' => $s['token'], 'email_match' => $s['email_match'], 'field_map' => $s['field_map'] ? json_decode($s['field_map'], true) : new \stdClass(), 'create_trip' => (bool) $s['create_trip'],
            'owner_id' => $s['owner_id'] ? (int) $s['owner_id'] : null, 'enabled' => (bool) $s['enabled'], 'received_count' => (int) $s['received_count'], 'last_received_at' => $s['last_received_at']];
    }

    public function list(int $tenantId): array
    {
        return array_map(fn ($s) => $this->view($s), db_connect()->table('lead_sources')->where('tenant_id', $tenantId)->where('deleted_at', null)->orderBy('name')->get()->getResultArray());
    }

    public function events(int $tenantId, int $sourceId, int $limit = 30): array
    {
        $this->row($tenantId, $sourceId);
        return array_map(static fn ($e) => ['id' => (int) $e['id'], 'status' => $e['status'], 'reason' => $e['reason'], 'contact_id' => $e['contact_id'] ? (int) $e['contact_id'] : null, 'trip_id' => $e['trip_id'] ? (int) $e['trip_id'] : null, 'at' => $e['created_at']],
            db_connect()->table('lead_events')->where('tenant_id', $tenantId)->where('lead_source_id', $sourceId)->orderBy('id', 'DESC')->limit(max(1, min(100, $limit)))->get()->getResultArray());
    }

    // ---- ingestion -------------------------------------------------------------------------------------------------------

    /** Webhook door. @throws \OutOfBoundsException unknown/disabled token */
    public function ingestWebhook(string $token, array $payload): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) { throw new \OutOfBoundsException('Unknown lead source.'); }
        $s = db_connect()->table('lead_sources')->where('token', $token)->where('kind', 'webhook')->where('enabled', 1)->where('deleted_at', null)->get()->getRowArray();
        if (! $s) { throw new \OutOfBoundsException('Unknown lead source.'); }
        $parsed = LeadFieldExtractor::fromPayload($payload, $s['field_map'] ? (json_decode($s['field_map'], true) ?: []) : []);
        return $this->ingest($s, $parsed, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /** Email door: the enabled email source whose sender rule matches, or null. */
    public function matchEmailSource(int $tenantId, string $fromEmail): ?array
    {
        $from = strtolower($fromEmail);
        foreach (db_connect()->table('lead_sources')->where('tenant_id', $tenantId)->where('kind', 'email')->where('enabled', 1)->where('deleted_at', null)->get()->getResultArray() as $s) {
            $m = (string) $s['email_match'];
            if ($m !== '' && ($m[0] === '@' ? str_ends_with($from, $m) : $from === $m)) { return $s; }
        }
        return null;
    }

    public function ingestEmail(array $source, string $subject, string $body): array
    {
        $parsed = LeadFieldExtractor::fromEmail($subject, $body, $source['field_map'] ? (json_decode($source['field_map'], true) ?: []) : []);
        return $this->ingest($source, $parsed, mb_substr($subject . "\n" . strip_tags($body), 0, 8000));
    }

    /** @return array{status:string,reason?:string,contact_id?:int,trip_id?:int} */
    private function ingest(array $s, array $parsed, string $raw): array
    {
        $tenantId = (int) $s['tenant_id'];
        $lead = LeadFieldExtractor::normalise($parsed, $this->today());
        $db = db_connect();
        $log = function (string $status, ?string $reason, ?int $contactId, ?int $tripId, string $hash) use ($db, $s, $tenantId, $raw): void {
            $db->table('lead_events')->insert(['tenant_id' => $tenantId, 'lead_source_id' => (int) $s['id'], 'status' => $status, 'reason' => $reason ? mb_substr($reason, 0, 255) : null, 'contact_id' => $contactId, 'trip_id' => $tripId,
                'dedupe_hash' => $hash, 'payload' => mb_substr($raw, 0, 20000), 'created_at' => $this->stamp()]);
            $db->query('UPDATE lead_sources SET received_count = received_count + 1, last_received_at = ? WHERE id = ?', [$this->stamp(), $s['id']]);
        };
        $hash = sha1(json_encode([$lead['phone'], $lead['email'], strtolower((string) ($lead['trip']['destination_text'] ?? '')), $lead['trip']['start_date'] ?? '', $lead['message']]));

        if ($lead['phone'] === '' && $lead['email'] === null) {
            $why = 'No usable phone number or email in this lead' . ($lead['issues'] ? ' (' . implode('; ', $lead['issues']) . ')' : '') . '.';
            $log('rejected', $why, null, null, $hash);
            return ['status' => 'rejected', 'reason' => $why];
        }
        $recent = $db->query("SELECT COUNT(*) n FROM lead_events WHERE lead_source_id = ? AND dedupe_hash = ? AND status IN ('created','existing') AND created_at >= ?", [$s['id'], $hash, date('Y-m-d H:i:s', $this->ts() - self::DUPLICATE_DAYS * 86400)])->getRowArray();
        if ((int) $recent['n'] > 0) { $log('duplicate', 'Same lead received again', null, null, $hash); return ['status' => 'duplicate']; }

        try {
            $res = (new ContactDedupeService(new ContactModel(), new ContactFieldValueModel()))->upsert($tenantId, array_filter([
                'wa_number' => $lead['phone'] ?: null, 'email' => $lead['email'], 'name' => $lead['name'], 'source' => 'portal', 'tags' => [$s['name']],
                'city' => $lead['trip']['origin_city'] ?? null,
                '_attribution' => ['platform' => 'portal', 'channel' => mb_substr((string) $s['name'], 0, 40), 'touched_at' => $this->stamp()],
            ], static fn ($v) => $v !== null && $v !== ''));
            $contactId = (int) $res['contact_id'];
            $tripId = null; $status = 'created'; $note = "Lead from {$s['name']}" . ($lead['message'] !== '' ? ': ' . $lead['message'] : '');
            if ((int) $s['create_trip'] === 1) {
                $tripId = $this->openEnquiry($tenantId, $contactId, $lead['trip']);
                if ($tripId !== null) { $status = 'existing'; }
                else { $tripId = $this->createTrip($tenantId, $contactId, $s, $lead); }
            }
            (new ActivityModel())->log($tenantId, 'system', 'contact', $contactId, ['subject' => $note, 'body' => $lead['extras'] ? json_encode($lead['extras'], JSON_UNESCAPED_UNICODE) : null]);
            $log($status, $status === 'existing' ? 'Open enquiry for this traveller already exists — note added to it' : null, $contactId, $tripId, $hash);
            return ['status' => $status, 'contact_id' => $contactId] + ($tripId ? ['trip_id' => $tripId] : []);
        } catch (\Throwable $e) {
            log_message('error', 'portal lead failed: ' . $e->getMessage());
            $log('error', 'Could not save this lead: ' . $e->getMessage(), null, null, $hash);
            throw new \RuntimeException('Could not save this lead.');
        }
    }

    private function openEnquiry(int $tenantId, int $contactId, array $trip): ?int
    {
        return (new TripService())->openEnquiryFor($tenantId, $contactId, $trip['destination_text'] ?? null, self::OPEN_ENQUIRY_DAYS, $this->ts());
    }

    private function createTrip(int $tenantId, int $contactId, array $s, array $lead): int
    {
        $t = $lead['trip'];
        $who = $lead['name'] ?: 'Customer';
        $data = array_intersect_key($t, array_flip(['trip_type', 'destination_text', 'is_international', 'origin_city', 'start_date', 'travel_month', 'nights', 'adults', 'children', 'budget_max', 'budget_basis']));
        $req = trim(($lead['message'] !== '' ? $lead['message'] : '') . ($lead['extras'] ? "\n\nFrom {$s['name']}: " . implode('; ', array_map(static fn ($k, $v) => "$k: $v", array_keys($lead['extras']), $lead['extras'])) : ''));
        $trip = (new TripService())->create($tenantId, $data + ['title' => mb_substr(($t['destination_text'] ?? 'Travel') . ' enquiry – ' . $who, 0, 200), 'contact_id' => $contactId, 'owner_id' => $s['owner_id'] ?: null, 'source' => 'portal',
            'requirements' => $req !== '' ? mb_substr($req, 0, 2000) : null], null);
        return (int) $trip['id'];
    }
}
