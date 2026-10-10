<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Leads\ContactDedupeService;
use App\Services\Marketing\PlatformConsent;

/**
 * Who TripSarthi may send its OWN WhatsApp marketing to - and putting them into the marketing workspace so the normal Campaigns engine
 * (templates, 24-hour rules, STOP handling, quality gate, scheduling) does the sending.
 *
 * CONSENT FIRST: every segment contains only people who explicitly ticked "WhatsApp me about TripSarthi" AND gave a usable number
 * (workspace owners at sign-up/profile, website visitors on the contact form, partners at invite/portal). Nobody is ever added on assumption.
 *
 *  - sync() adds each member to the marketing workspace as an opted-in contact tagged `ts-platform` + `ts-<segment>`, WITHOUT starting any flow.
 *  - An existing contact keeps its state: someone who replied STOP in the marketing workspace stays opted out (counted as "skipped").
 *  - Withdrawn consent is honoured: contacts we created (tag `ts-platform`) whose number no longer belongs to ANY consenting source are
 *    switched to opt_in = 0 on the next sync.
 */
final class PlatformAudienceService
{
    public const TAG_ALL = 'ts-platform';
    public const SEGMENTS = [
        'owners'              => ['label' => 'Workspace owners', 'description' => 'Everyone who runs a TripSarthi workspace and agreed to WhatsApp updates.'],
        'owners_trial_ending' => ['label' => 'Plan ending within 7 days', 'description' => 'Opted-in owners whose current paid period or trial ends in the next 7 days.'],
        'owners_free'         => ['label' => 'On the free plan 7+ days', 'description' => 'Opted-in owners still on the free plan a week after signing up (upgrade nudge).'],
        'owners_lapsed'       => ['label' => 'Lapsed customers', 'description' => 'Opted-in owners who paid before but are back on the free plan (win-back).'],
        'partners'            => ['label' => 'Active partners', 'description' => 'Referral partners who agreed to program updates on WhatsApp.'],
        'website_leads'       => ['label' => 'Website leads', 'description' => 'People who left a number on the website contact form and ticked WhatsApp.'],
    ];

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? $this->ts()); }

    /** The workspace that runs TripSarthi's own marketing, or null when none is chosen. */
    public function marketingTenant(): ?int
    {
        $v = (int) PlatformSettings::get(PlatformSettings::MARKETING_TENANT, '0');
        return $v > 0 ? $v : null;
    }

    /** @return list<array{key:string,label:string,description:string,count:int,last_sync:?array}> */
    public function segments(): array
    {
        $last = [];
        foreach (db_connect()->query('SELECT s.* FROM platform_audience_syncs s JOIN (SELECT segment, MAX(id) mid FROM platform_audience_syncs GROUP BY segment) m ON m.mid = s.id')->getResultArray() as $r) { $last[$r['segment']] = $r; }
        $out = [];
        foreach (self::SEGMENTS as $key => $def) {
            $l = $last[$key] ?? null;
            $out[] = ['key' => $key, 'label' => $def['label'], 'description' => $def['description'], 'count' => count($this->members($key)),
                'last_sync' => $l ? ['at' => $l['created_at'], 'added' => (int) $l['added'], 'already_there' => (int) $l['already_there'], 'withdrawn' => (int) $l['withdrawn'], 'skipped' => (int) $l['skipped']] : null];
        }
        return $out;
    }

    /** @return array{count:int,sample:list<array>} a handful of members with the number MASKED - enough to check the segment, never a contact list */
    public function preview(string $key): array
    {
        $m = $this->members($key);
        return ['count' => count($m), 'sample' => array_map(fn ($r) => ['name' => $r['name'], 'phone' => '••••••' . substr($r['phone'], -4), 'context' => $r['context']], array_slice($m, 0, 5))];
    }

    /**
     * Every consenting member of a segment, de-duplicated by number.
     * @return list<array{name:string,phone:string,email:?string,context:string}>
     */
    public function members(string $key): array
    {
        if (! isset(self::SEGMENTS[$key])) { throw new \InvalidArgumentException('Unknown segment.'); }
        $db = db_connect(); $now = $this->stamp(); $own = $this->marketingTenant() ?? 0;
        $rows = [];
        $owners = "SELECT u.name, u.email, u.phone, t.name context FROM users u JOIN tenants t ON t.id = u.tenant_id AND t.deleted_at IS NULL AND t.status = 'active'
                    WHERE u.role = 'owner' AND u.deleted_at IS NULL AND u.wa_marketing_opt_in = 1 AND u.phone IS NOT NULL AND u.is_platform_admin = 0 AND t.id <> ?";
        switch ($key) {
            case 'owners':
                $rows = $db->query($owners, [$own])->getResultArray(); break;
            case 'owners_trial_ending':
                $rows = $db->query($owners . " AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.tenant_id = t.id AND s.status = 'active' AND s.current_period_end > ? AND s.current_period_end <= ?)", [$own, $now, $this->stamp($this->ts() + 7 * 86400)])->getResultArray(); break;
            case 'owners_free':
                $rows = $db->query($owners . " AND t.plan = 'free' AND t.created_at <= ?", [$own, $this->stamp($this->ts() - 7 * 86400)])->getResultArray(); break;
            case 'owners_lapsed':
                $rows = $db->query($owners . " AND t.plan = 'free' AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.tenant_id = t.id AND s.status IN ('cancelled','halted','downgraded') AND s.amount_paise > 0 AND s.razorpay_sub_id NOT LIKE 'admin-%')", [$own])->getResultArray(); break;
            case 'partners':
                $rows = $db->query("SELECT name, email, phone, COALESCE(company, 'Partner') context FROM partners WHERE status = 'active' AND wa_marketing_opt_in = 1 AND phone IS NOT NULL")->getResultArray(); break;
            case 'website_leads':
                $rows = $db->query("SELECT name, email, phone, COALESCE(NULLIF(company, ''), topic) context FROM contact_enquiries WHERE wa_opt_in = 1 AND status <> 'spam' AND phone <> '' ORDER BY id DESC")->getResultArray(); break;
        }
        return $this->clean($rows);
    }

    /** Normalise numbers, drop the unusable, and keep one entry per number. */
    private function clean(array $rows): array
    {
        $seen = []; $out = [];
        foreach ($rows as $r) {
            $phone = PlatformConsent::normalizePhone((string) $r['phone']);
            if ($phone === null || isset($seen[$phone])) { continue; }
            $seen[$phone] = true;
            $out[] = ['name' => mb_substr(trim((string) $r['name']), 0, 120) ?: 'TripSarthi contact', 'phone' => $phone, 'email' => ($r['email'] ?? null) ?: null, 'context' => (string) ($r['context'] ?? '')];
        }
        return $out;
    }

    /** Every number that currently has consent, in ANY segment's source. */
    private function allConsenting(): array
    {
        $all = [];
        foreach (array_keys(self::SEGMENTS) as $k) { if (str_starts_with($k, 'owners') && $k !== 'owners') { continue; } foreach ($this->members($k) as $m) { $all[$m['phone']] = true; } }
        return $all;
    }

    /**
     * Adds a segment to the marketing workspace (idempotent) and withdraws anyone who has since taken their consent back.
     * @return array{added:int,already_there:int,skipped:int,withdrawn:int,members:int}
     */
    public function sync(string $key, int $adminId): array
    {
        $tenant = $this->marketingTenant() ?? throw new \DomainException('Choose the workspace that runs TripSarthi marketing first.');
        $db = db_connect();
        $db->table('tenants')->where('id', $tenant)->where('deleted_at', null)->countAllResults() ?: throw new \DomainException('The chosen marketing workspace no longer exists. Choose another.');
        $members = $this->members($key);
        $dedupe = new ContactDedupeService(new \App\Models\ContactModel(), new \App\Models\ContactFieldValueModel()); $added = 0; $there = 0; $skipped = 0;
        foreach ($members as $m) {
            $existing = $db->table('contacts')->select('id, opt_in')->where('tenant_id', $tenant)->whereIn('wa_number', \App\Services\Leads\WaNumberNormalizer::variants($m['phone']))->where('deleted_at', null)->get()->getRowArray();   // '+91…' and '91…' are the same person
            if ($existing !== null && (int) $existing['opt_in'] === 0) { $skipped++; continue; }                     // replied STOP (or was switched off): never re-opted-in
            $r = $dedupe->upsert($tenant, ['wa_number' => $m['phone'], 'name' => $m['name'], 'email' => $m['email'], 'source' => 'manual', 'opt_in' => 1, 'tags' => [self::TAG_ALL, 'ts-' . str_replace('_', '-', $key)]], false);
            $r['action'] === 'inserted' ? $added++ : $there++;
        }
        $withdrawn = $this->withdraw($tenant);
        $db->table('platform_audience_syncs')->insert(['segment' => $key, 'marketing_tenant_id' => $tenant, 'added' => $added, 'already_there' => $there, 'withdrawn' => $withdrawn, 'skipped' => $skipped, 'created_by' => $adminId, 'created_at' => $this->stamp()]);
        return ['added' => $added, 'already_there' => $there, 'skipped' => $skipped, 'withdrawn' => $withdrawn, 'members' => count($members)];
    }

    /** Switches off contacts WE added whose number has no consent left anywhere. Contacts the team added by hand are never touched. */
    private function withdraw(int $tenant): int
    {
        $db = db_connect(); $keep = $this->allConsenting();
        $rows = $db->query("SELECT DISTINCT c.id, c.wa_number FROM contacts c JOIN contact_tags ct ON ct.contact_id = c.id JOIN tags t ON t.id = ct.tag_id AND t.tenant_id = c.tenant_id AND t.name = ?
                             WHERE c.tenant_id = ? AND c.deleted_at IS NULL AND c.opt_in = 1 AND c.wa_number IS NOT NULL", [self::TAG_ALL, $tenant])->getResultArray();
        $ids = [];
        foreach ($rows as $r) { if (! isset($keep[ltrim((string) $r['wa_number'], '+')])) { $ids[] = (int) $r['id']; } }
        if ($ids !== []) { $db->table('contacts')->whereIn('id', $ids)->update(['opt_in' => 0, 'updated_at' => $this->stamp()]); }
        return count($ids);
    }
}
