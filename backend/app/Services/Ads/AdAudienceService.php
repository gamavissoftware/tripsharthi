<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Models\ContactModel;
use App\Services\Crm\AuditLogger;
use App\Services\Leads\SegmentResolver;

/**
 * Meta custom + lookalike audiences from CRM contacts.
 *
 * Privacy (DPDP Act): sharing customer details with an ad platform needs a lawful basis, so
 *  - the person creating the audience must explicitly confirm they have the right to use these details for advertising;
 *  - only contacts with opt_in = 1 are ever included, and a refresh REMOVES anyone who has since opted out;
 *  - phone numbers and emails are normalised and SHA-256 hashed BEFORE leaving the server (Meta never receives raw values from us);
 *  - we keep only the hashes (not raw values) of who was uploaded, so removals work even after a contact is deleted;
 *  - every create / refresh / delete is audited.
 * Meta needs at least 100 matched people before an audience can be used for delivery or as a lookalike seed.
 */
final class AdAudienceService
{
    public const MIN_SEED = 100;
    public const BATCH = 10_000;
    public const SOURCES = ['booked' => 'Customers who have booked', 'enquiries_unbooked' => 'Enquiries in the last 90 days that did not book', 'segment' => 'A saved contact segment', 'all_optin' => 'All contacts who agreed to be contacted'];

    /** @param null|callable(int):MetaMarketingClient $clientFactory tests inject a client */
    public function __construct(private readonly mixed $clientFactory = null, private readonly ?int $now = null) {}
    private function stamp(): string { return date('Y-m-d H:i:s', $this->now ?? time()); }

    private function client(int $tenantId): MetaMarketingClient
    {
        if (is_callable($this->clientFactory)) { return ($this->clientFactory)($tenantId); }
        $a = MetaAdapter::forTenant($tenantId, AdsMockHttp::enabled() ? AdsMockHttp::handler($tenantId) : null);
        if (! $a) { throw new AdsApiException('Meta is not connected for campaign management. Connect it first.', AdsApiException::AUTH); }
        return $a->client();
    }

    // ---- hashing (pure) ------------------------------------------------------------------------------------------------

    /** Meta wants phone as digits with country code, no + or leading zeros. Indian 10-digit mobiles get 91. */
    public static function hashPhone(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw) ?? '';
        $d = ltrim($d, '0');
        if (strlen($d) === 10 && $d[0] >= '6') { $d = '91' . $d; }
        return strlen($d) >= 8 && strlen($d) <= 15 ? hash('sha256', $d) : null;
    }

    public static function hashEmail(?string $raw): ?string
    {
        $e = strtolower(trim((string) $raw));
        return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) ? hash('sha256', $e) : null;
    }

    /** @return array<int,array{0:?string,1:?string}> contact id => [phone hash, email hash]; contacts with neither are dropped */
    public static function identifiers(array $contacts): array
    {
        $out = [];
        foreach ($contacts as $c) {
            $p = self::hashPhone($c['wa_number'] ?? null); $e = self::hashEmail($c['email'] ?? null);
            if ($p !== null || $e !== null) { $out[(int) $c['id']] = [$p, $e]; }
        }
        return $out;
    }

    // ---- who qualifies ---------------------------------------------------------------------------------------------------

    /** Contacts that currently qualify. ALWAYS opt_in = 1. @return list<array{id:int,wa_number:?string,email:?string}> */
    public function qualifying(int $tenantId, string $source, ?int $ref): array
    {
        $m = (new ContactModel())->setTenant($tenantId)->select('contacts.id, contacts.wa_number, contacts.email')->where('contacts.opt_in', 1);
        $db = db_connect();
        switch ($source) {
            case 'booked':
                $ids = array_column($db->query("SELECT DISTINCT contact_id FROM bookings WHERE tenant_id = ? AND deleted_at IS NULL AND status <> 'cancelled' AND contact_id IS NOT NULL", [$tenantId])->getResultArray(), 'contact_id'); break;
            case 'enquiries_unbooked':
                $ids = array_column($db->query("SELECT DISTINCT t.contact_id FROM trips t WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND t.contact_id IS NOT NULL AND t.created_at >= ? AND t.status IN ('enquiry','quoted','negotiating','lost')
                    AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.tenant_id = t.tenant_id AND b.contact_id = t.contact_id AND b.deleted_at IS NULL AND b.status <> 'cancelled')", [$tenantId, date('Y-m-d H:i:s', ($this->now ?? time()) - 90 * 86400)])->getResultArray(), 'contact_id'); break;
            case 'segment':
                if (! $ref) { throw new \InvalidArgumentException('Choose a segment.'); }
                (new SegmentResolver())->apply($m, ['segment_id' => $ref]);
                return array_map(static fn ($r) => ['id' => (int) $r['id'], 'wa_number' => $r['wa_number'], 'email' => $r['email']], $m->findAll());
            case 'all_optin':
                return array_map(static fn ($r) => ['id' => (int) $r['id'], 'wa_number' => $r['wa_number'], 'email' => $r['email']], $m->findAll());
            default:
                throw new \InvalidArgumentException('Unknown audience source.');
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (! $ids) { return []; }
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'wa_number' => $r['wa_number'], 'email' => $r['email']], $m->whereIn('contacts.id', $ids)->findAll());
    }

    // ---- create ----------------------------------------------------------------------------------------------------------

    public function create(int $tenantId, ?int $userId, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) { throw new \InvalidArgumentException('Give the audience a name.'); }
        $account = (string) ($in['account_id'] ?? '');
        if (! preg_match('/^act_\d+$/', $account)) { throw new \InvalidArgumentException('Choose an ad account.'); }
        $source = (string) ($in['source_type'] ?? '');
        if (! isset(self::SOURCES[$source])) { throw new \InvalidArgumentException('Choose who to include.'); }
        if (empty($in['consent_confirmed'])) { throw new \InvalidArgumentException('Confirm that you have the right to use these customers’ details for advertising (their consent / your privacy policy). Details are shared with Meta in hashed form.'); }
        $ref = ! empty($in['source_ref']) ? (int) $in['source_ref'] : null;
        $people = self::identifiers($this->qualifying($tenantId, $source, $ref));
        if (! $people) { throw new \InvalidArgumentException('No contacts qualify (they need a phone number or email and must have agreed to be contacted).'); }

        $db = db_connect();
        $db->table('ad_audiences')->insert(['tenant_id' => $tenantId, 'platform' => 'meta', 'name' => $name, 'kind' => 'custom', 'source_type' => $source, 'source_ref' => $ref, 'account_ref' => $account, 'status' => 'creating',
            'created_by' => $userId, 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        $id = (int) $db->insertID();
        try {
            $client = $this->client($tenantId);
            $ext = $client->createAudience($account, ['name' => $name, 'subtype' => 'CUSTOM', 'description' => 'TravelPilot · ' . self::SOURCES[$source], 'customer_file_source' => 'USER_PROVIDED_ONLY']);
            $db->table('ad_audiences')->where('id', $id)->update(['external_id' => $ext]);
            $this->push($tenantId, $client, $id, $ext, $people, []);
            $db->table('ad_audiences')->where('id', $id)->update(['status' => 'ready', 'last_error' => null, 'last_synced_at' => $this->stamp(), 'updated_at' => $this->stamp(), 'member_count' => count($people)]);
        } catch (AdsApiException $e) {
            $db->table('ad_audiences')->where('id', $id)->update(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => $this->stamp()]);
            AuditLogger::log('ad_audience.create_failed', 'ad_audience', $id, null, ['error' => $e->getMessage()], $tenantId, $userId);
            throw $e;
        }
        AuditLogger::log('ad_audience.create', 'ad_audience', $id, null, ['source' => $source, 'members' => count($people), 'consent_confirmed' => true], $tenantId, $userId);
        return $this->view($this->row($tenantId, $id));
    }

    /** @param array<int,array{0:?string,1:?string}> $add contact id => hashes;  @param list<array{0:?string,1:?string}> $remove hashes of people to remove */
    private function push(int $tenantId, MetaMarketingClient $client, int $localId, string $ext, array $add, array $remove): void
    {
        $schema = ['PHONE_SHA256', 'EMAIL_SHA256'];
        $db = db_connect();
        foreach (array_chunk($add, self::BATCH, true) as $chunk) {
            $client->addAudienceUsers($ext, $schema, array_map(static fn ($h) => [(string) $h[0], (string) $h[1]], array_values($chunk)));
            foreach ($chunk as $cid => $h) {
                $db->query('INSERT IGNORE INTO ad_audience_members (tenant_id, audience_id, contact_id, phone_hash, email_hash, added_at) VALUES (?,?,?,?,?,?)', [$tenantId, $localId, $cid, $h[0], $h[1], $this->stamp()]);
            }
        }
        foreach (array_chunk($remove, self::BATCH) as $chunk) {
            $client->removeAudienceUsers($ext, $schema, array_map(static fn ($h) => [(string) $h[0], (string) $h[1]], $chunk));
        }
    }

    public function createLookalike(int $tenantId, ?int $userId, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) { throw new \InvalidArgumentException('Give the audience a name.'); }
        $seed = $this->row($tenantId, (int) ($in['seed_audience_id'] ?? 0));
        if ($seed['kind'] !== 'custom' || $seed['status'] !== 'ready') { throw new \InvalidArgumentException('Choose a ready customer audience as the seed.'); }
        if ((int) $seed['member_count'] < self::MIN_SEED) { throw new \InvalidArgumentException('A lookalike needs a seed of at least ' . self::MIN_SEED . ' people; this one has ' . (int) $seed['member_count'] . '. Add more contacts first (1,000+ gives much better results).'); }
        $ratio = (float) ($in['ratio_pct'] ?? 1);
        if ($ratio < 1 || $ratio > 10) { throw new \InvalidArgumentException('Lookalike size must be between 1% and 10% of the population (1% is the closest match).'); }
        $db = db_connect();
        $db->table('ad_audiences')->insert(['tenant_id' => $tenantId, 'platform' => 'meta', 'name' => $name, 'kind' => 'lookalike', 'source_type' => 'lookalike', 'source_ref' => (int) $seed['id'], 'account_ref' => $seed['account_ref'], 'status' => 'creating',
            'params' => json_encode(['country' => 'IN', 'ratio_pct' => $ratio]), 'created_by' => $userId, 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        $id = (int) $db->insertID();
        try {
            $ext = $this->client($tenantId)->createAudience($seed['account_ref'], ['name' => $name, 'subtype' => 'LOOKALIKE', 'origin_audience_id' => $seed['external_id'], 'lookalike_spec' => ['type' => 'similarity', 'country' => 'IN', 'ratio' => round($ratio / 100, 2)]]);
            $db->table('ad_audiences')->where('id', $id)->update(['external_id' => $ext, 'status' => 'ready', 'last_synced_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        } catch (AdsApiException $e) {
            $db->table('ad_audiences')->where('id', $id)->update(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => $this->stamp()]);
            throw $e;
        }
        AuditLogger::log('ad_audience.lookalike', 'ad_audience', $id, null, ['seed' => (int) $seed['id'], 'ratio_pct' => $ratio], $tenantId, $userId);
        return $this->view($this->row($tenantId, $id));
    }

    // ---- keep it current ------------------------------------------------------------------------------------------------------

    /** Add contacts that now qualify; REMOVE those who no longer do (opted out, deleted, now booked…). Returns what changed. */
    public function refresh(int $tenantId, int $id, ?int $userId = null): array
    {
        $a = $this->row($tenantId, $id);
        if ($a['kind'] !== 'custom') { throw new \DomainException('Lookalike audiences are refreshed by Meta itself; refresh the seed audience instead.'); }
        if (empty($a['external_id'])) { throw new \DomainException('This audience was never created on Meta. Delete it and create it again.'); }
        $now = self::identifiers($this->qualifying($tenantId, (string) $a['source_type'], $a['source_ref'] ? (int) $a['source_ref'] : null));
        $db = db_connect();
        $have = [];
        foreach ($db->table('ad_audience_members')->where('audience_id', $id)->get()->getResultArray() as $m) { $have[(int) $m['contact_id']] = $m; }
        $add = array_diff_key($now, $have);
        $gone = array_diff_key($have, $now);
        $remove = array_map(static fn ($m) => [$m['phone_hash'], $m['email_hash']], array_values($gone));
        try { $this->push($tenantId, $this->client($tenantId), $id, (string) $a['external_id'], $add, $remove); }
        catch (AdsApiException $e) { $db->table('ad_audiences')->where('id', $id)->update(['last_error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => $this->stamp()]); throw $e; }
        if ($gone) { $db->table('ad_audience_members')->where('audience_id', $id)->whereIn('contact_id', array_keys($gone))->delete(); }
        $db->table('ad_audiences')->where('id', $id)->update(['member_count' => count($now), 'status' => 'ready', 'last_error' => null, 'last_synced_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        AuditLogger::log('ad_audience.refresh', 'ad_audience', $id, null, ['added' => count($add), 'removed' => count($gone)], $tenantId, $userId);
        return ['added' => count($add), 'removed' => count($gone), 'members' => count($now)];
    }

    /** Meta's own estimate of the audience size (lower bound), when available. */
    public function estimate(int $tenantId, int $id): ?int
    {
        $a = $this->row($tenantId, $id);
        if (empty($a['external_id'])) { return null; }
        try { $r = $this->client($tenantId)->audience((string) $a['external_id']); return isset($r['approximate_count_lower_bound']) ? (int) $r['approximate_count_lower_bound'] : null; }
        catch (AdsApiException) { return null; }
    }

    public function delete(int $tenantId, int $id, ?int $userId = null): void
    {
        $a = $this->row($tenantId, $id);
        if (! empty($a['external_id'])) {
            foreach (db_connect()->table('ad_campaigns')->select('name, spec')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->get()->getResultArray() as $c) {
                // decode, don't string-match: MySQL re-serialises JSON columns with different spacing and key order
                if (self::references(json_decode((string) $c['spec'], true) ?: [], $id)) { throw new \DomainException("This audience is used by the active campaign “{$c['name']}”. Pause that campaign first."); }
            }
            try { $this->client($tenantId)->delete((string) $a['external_id']); } catch (AdsApiException) { /* already gone, or not ours to delete: still remove locally */ }
        }
        $db = db_connect();
        $db->table('ad_audience_members')->where('audience_id', $id)->delete();
        $db->table('ad_audiences')->where('id', $id)->update(['deleted_at' => $this->stamp(), 'status' => 'error', 'last_error' => 'deleted']);
        AuditLogger::log('ad_audience.delete', 'ad_audience', $id, null, null, $tenantId, $userId);
    }

    /** Does this campaign spec use the local audience id anywhere (campaign-wide or in an ad set)? */
    private static function references(array $spec, int $id): bool
    {
        foreach (array_merge((array) ($spec['audiences'] ?? []), ...array_map(static fn ($s) => (array) ($s['audiences'] ?? []), (array) ($spec['adsets'] ?? []))) as $a) {
            if ((int) ($a['audience_id'] ?? 0) === $id) { return true; }
        }
        return false;
    }

    // ---- reading / use in campaigns ---------------------------------------------------------------------------------------------

    private function row(int $tenantId, int $id): array
    {
        $r = db_connect()->table('ad_audiences')->where('tenant_id', $tenantId)->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if (! $r) { throw new \InvalidArgumentException('Audience not found.'); }
        return $r;
    }

    private function view(array $a): array
    {
        return ['id' => (int) $a['id'], 'name' => $a['name'], 'kind' => $a['kind'], 'source_type' => $a['source_type'], 'source_label' => self::SOURCES[$a['source_type']] ?? ($a['kind'] === 'lookalike' ? 'Lookalike' : $a['source_type']),
            'account_ref' => $a['account_ref'], 'status' => $a['status'], 'member_count' => (int) $a['member_count'], 'params' => $a['params'] ? json_decode($a['params'], true) : null, 'seed_audience_id' => $a['kind'] === 'lookalike' ? (int) $a['source_ref'] : null,
            'last_error' => $a['last_error'], 'last_synced_at' => $a['last_synced_at'], 'usable' => $a['status'] === 'ready' && ! empty($a['external_id']) && ($a['kind'] === 'lookalike' || (int) $a['member_count'] >= self::MIN_SEED)];
    }

    public function list(int $tenantId): array
    {
        return array_map(fn ($r) => $this->view($r), db_connect()->table('ad_audiences')->where('tenant_id', $tenantId)->where('deleted_at', null)->orderBy('id', 'DESC')->get()->getResultArray());
    }

    /** Spec `audiences: [{audience_id, mode}]` (local ids) -> `[{id: <meta id>, mode}]`; same for each ad set. Unusable / wrong-account audiences are errors. */
    public function resolveForMeta(int $tenantId, array $spec): array
    {
        $map = function (array $list) use ($tenantId, $spec): array {
            $out = [];
            foreach ($list as $a) {
                $row = $this->row($tenantId, (int) ($a['audience_id'] ?? 0));
                if ($row['status'] !== 'ready' || empty($row['external_id'])) { throw new \InvalidArgumentException("The audience “{$row['name']}” is not ready yet."); }
                if ($row['account_ref'] !== (string) ($spec['account_id'] ?? '')) { throw new \InvalidArgumentException("The audience “{$row['name']}” belongs to a different ad account."); }
                $out[] = ['id' => (string) $row['external_id'], 'mode' => ($a['mode'] ?? 'include') === 'exclude' ? 'exclude' : 'include'];
            }
            return $out;
        };
        if (! empty($spec['audiences'])) { $spec['audiences'] = $map((array) $spec['audiences']); }
        foreach ((array) ($spec['adsets'] ?? []) as $i => $set) { if (! empty($set['audiences'])) { $spec['adsets'][$i]['audiences'] = $map((array) $set['audiences']); } }
        return $spec;
    }
}
