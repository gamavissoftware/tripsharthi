<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\TagModel;
use App\Services\Crm\AccountResolver;
use App\Services\Flow\FlowTriggerService;

/**
 * Contact upsert with dedupe on (tenant_id, wa_number).
 *
 * Insert rules:
 *   - Sets all provided fields + source + opt_in=true.
 *
 * Update rules (same wa_number found):
 *   - Overwrites name / email ONLY when the incoming value is non-blank.
 *   - Does NOT touch status or opt_in (preserves CRM state).
 *   - Custom field values are upserted identically on insert and update.
 *
 * Relations (both insert and update):
 *   - 'tags'    → tag get-or-create by name, then contact_tags link. Additive:
 *                 re-importing never removes a tag a human added by hand.
 *   - 'company' → account get-or-create by name, linked via contacts.account_id,
 *                 with 'company_industry' stored on the account.
 *
 * Returns an array: ['contact_id' => int, 'action' => 'inserted'|'updated']
 */
class ContactDedupeService
{
    /**
     * Columns written straight onto `contacts` when the mapping supplies them.
     * Kept in sync with ColumnMapper::STANDARD_FIELDS minus the keys handled
     * explicitly below (wa_number, name, email, status, source, opt_in).
     */
    private const DIRECT_FIELDS = [
        'job_title', 'phone_secondary', 'business_type',
        'city', 'state', 'country', 'language', 'remarks',
    ];

    /** tags.name is varchar(50); longer names are truncated rather than rejected. */
    private const TAG_MAX_LEN = 50;

    /** @var array<string,int> "tenantId:lowercased name" => tag id */
    private array $tagCache = [];

    /** Shared with the contact form, so both create companies the same way. */
    private AccountResolver $accounts;

    public function __construct(
        private ContactModel           $contactModel,
        private ContactFieldValueModel $cfvModel,
        ?AccountResolver               $accounts = null,
    ) {
        $this->accounts = $accounts ?? new AccountResolver();
    }

    /**
     * @param  int    $tenantId
     * @param  array  $data  Keys: wa_number OR email (at least one), name, status,
     *                             source, opt_in, custom_fields (assoc field_key => value)
     * @return array{contact_id: int, action: string}
     */
    public function upsert(int $tenantId, array $data, bool $fireTriggers = true): array
    {
        // Ad attribution rides along under a private key so every lead source
        // can attach it without touching the contact columns.
        $attribution = $data['_attribution'] ?? null;
        unset($data['_attribution']);

        $result = $this->doUpsert($tenantId, $data, $fireTriggers);

        if (is_array($attribution) && $attribution && ! empty($result['contact_id'])) {
            try {
                (new \App\Services\Travel\AttributionService())->record($tenantId, (int) $result['contact_id'], $attribution);
            } catch (\Throwable $e) {
                // Attribution is best-effort — never lose the lead over it.
                log_message('error', 'attribution record failed: ' . $e->getMessage());
            }
        }
        // Phone alert for a genuinely NEW lead from an ad / website / email (WhatsApp-initiated leads are announced by the
        // inbound-message hook, which knows the message). Bulk imports and re-imports never reach here with triggers on.
        if ($fireTriggers && ($result['action'] ?? '') === 'inserted' && in_array((string) ($data['source'] ?? ''), ['meta_lead_ads', 'google_lead_forms', 'web_form', 'email_inbound', 'portal'], true)) {
            $contactId = (int) $result['contact_id'];
            \App\Services\Push\PushNotifier::safely(function ($n) use ($tenantId, $contactId, $data, $attribution) {
                $c = $this->contactModel->setTenant($tenantId)->find($contactId);
                if (! $c) { return; }
                $campaign = is_array($attribution) ? ($attribution['campaign_name'] ?? null) : null;
                if (! $campaign && is_array($attribution) && ! empty($attribution['campaign_id'])) {
                    $r = db_connect()->table('ad_campaigns')->select('name')->where('tenant_id', $tenantId)->where('external_id', (string) $attribution['campaign_id'])->get()->getRowArray();
                    $campaign = $r['name'] ?? null;
                }
                \App\Services\Push\EventPush::lead($n, $tenantId, is_array($c) ? $c : (array) $c, (string) $data['source'], $campaign ?: null);
            });
        }
        return $result;
    }

    private function doUpsert(int $tenantId, array $data, bool $fireTriggers): array
    {
        $waNumber = $data['wa_number'] ?? '';
        $email    = trim((string) ($data['email'] ?? ''));
        if ($waNumber === '' && $email === '') {
            throw new \InvalidArgumentException('A wa_number or email is required for upsert.');
        }

        $this->contactModel->setTenant($tenantId);
        // Dedupe key: wa_number when present, else email (email-only contact).
        $existing = $waNumber !== ''
            ? $this->contactModel->findByWaNumber($waNumber)
            : $this->contactModel->findByEmail($email);

        $customFields = $data['custom_fields'] ?? [];
        unset($data['custom_fields']);

        // ── Relations, resolved before the insert/update split ────────
        // Both paths need them: a re-import must be able to add a tag or
        // attach a company to a contact that already exists.
        $tagNames = $this->normalizeTags($data['tags'] ?? null);
        unset($data['tags']);

        $accountId = $this->accounts->resolve(
            $tenantId,
            (string) ($data['company'] ?? ''),
            (string) ($data['company_industry'] ?? '')
        );
        unset($data['company'], $data['company_industry']);
        if ($accountId !== null) {
            $data['account_id'] = $accountId;
        }

        if ($existing !== null) {
            $contactId = (int) (is_array($existing) ? $existing['id'] : $existing->id);
            $updatePayload = $this->buildUpdatePayload($data);

            if (! empty($updatePayload)) {
                $this->contactModel->setTenant($tenantId)->update($contactId, $updatePayload);
            }

            $this->cfvModel->bulkSetForContact($contactId, $tenantId, $customFields);
            $this->applyTags($tenantId, $contactId, $tagNames);

            // A lead-ad submission from a number already in the CRM is still a
            // lead: the person just asked to be contacted. lead_created stays
            // insert-only, but the ad-platform triggers fire again so the
            // nurture flow runs (its reentry policy decides whether it may).
            $source = (string) ($data['source'] ?? '');
            if ($fireTriggers && $source === 'meta_lead_ads') {
                FlowTriggerService::fire('meta_lead_received', $tenantId, $contactId, ['source' => $source, 'returning' => true]);
            } elseif ($fireTriggers && $source === 'google_lead_forms') {
                FlowTriggerService::fire('google_lead_received', $tenantId, $contactId, ['source' => $source, 'returning' => true]);
            }

            return ['contact_id' => $contactId, 'action' => 'updated'];
        }

        // --- Insert ---
        // wa_number must be NULL (never '') when absent, so the UNIQUE(tenant_id,
        // wa_number) index doesn't collide across email-only contacts.
        $insertPayload = array_filter([
            'tenant_id'  => $tenantId,
            'wa_number'  => $waNumber !== '' ? $waNumber : null,
            'name'       => $data['name']   ?? null,
            'email'      => $data['email']  ?? null,
            'status'     => $data['status'] ?? 'new',
            'source'     => $data['source'] ?? 'manual',
            'opt_in'     => isset($data['opt_in']) ? (int) $data['opt_in'] : 1,
            'account_id' => $data['account_id'] ?? null,
        ], static fn ($v) => $v !== null);

        foreach (self::DIRECT_FIELDS as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $insertPayload[$field] = $data[$field];
            }
        }

        // Bypass scope injection — we already set tenant_id explicitly in payload
        $contactId = (int) $this->contactModel
            ->withoutTenantScope()
            ->insert($insertPayload, true);

        $this->cfvModel->bulkSetForContact($contactId, $tenantId, $customFields);
        $this->applyTags($tenantId, $contactId, $tagNames);

        // ── Fire flow triggers AFTER auto-commit ──────────────────────
        // The INSERT above is auto-committed at the SQL level (no open transaction).
        // The flow_start jobs are dequeued by a separate cron worker process, which
        // will see the committed contact row.
        $source = $data['source'] ?? 'manual';

        // A bulk import of old leads (MetaLeadsArchiveService) must not start
        // a welcome flow per row unless the operator asked for it.
        if (! $fireTriggers) {
            return ['contact_id' => $contactId, 'action' => 'inserted'];
        }

        // lead_created fires for ALL sources (manual, csv_import, web_form, whatsapp_inbound,
        // meta_lead_ads). Flows using this trigger react to any new contact.
        FlowTriggerService::fire('lead_created', $tenantId, $contactId, [
            'source' => $source,
        ]);

        // meta_lead_received fires ADDITIONALLY for Facebook/Instagram Lead Ads contacts.
        // The real ingestion path is Sprint 5 (Lead Ads webhook). This fires now for
        // any contact created with source=meta_lead_ads (e.g. via CSV import or manual).
        if ($source === 'meta_lead_ads') {
            FlowTriggerService::fire('meta_lead_received', $tenantId, $contactId, [
                'source' => $source,
            ]);
        }

        // google_lead_received fires ADDITIONALLY for Google Ads Lead Form contacts.
        // The real ingestion path is the Google webhook → google_lead_process job;
        // this also fires for any contact created with source=google_lead_forms.
        if ($source === 'google_lead_forms') {
            FlowTriggerService::fire('google_lead_received', $tenantId, $contactId, [
                'source' => $source,
            ]);
        }

        // Outbound webhook (Zapier / CRM): contact.created
        \App\Services\Webhooks\OutboundWebhookService::emit($tenantId, 'contact.created', [
            'contact_id' => $contactId,
            'wa_number'  => $waNumber,
            'name'       => $insertPayload['name'] ?? null,
            'source'     => $source,
        ]);

        return ['contact_id' => $contactId, 'action' => 'inserted'];
    }

    /**
     * Build the update payload: only fields that are explicitly provided and non-blank.
     * Does NOT include status or opt_in.
     */
    private function buildUpdatePayload(array $data): array
    {
        $payload = [];
        if (isset($data['name']) && $data['name'] !== '') {
            $payload['name'] = $data['name'];
        }
        if (isset($data['email']) && $data['email'] !== '') {
            $payload['email'] = $data['email'];
        }
        // source can be updated on re-import
        if (isset($data['source']) && $data['source'] !== '') {
            $payload['source'] = $data['source'];
        }
        // Linking a contact to a company on re-import is an enrichment, not a
        // state change — safe to apply. Never unset an existing link with null.
        if (! empty($data['account_id'])) {
            $payload['account_id'] = (int) $data['account_id'];
        }
        foreach (self::DIRECT_FIELDS as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $payload[$field] = $data[$field];
            }
        }
        return $payload;
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    /**
     * Split a mapped tag cell into clean tag names.
     *
     * Accepts an array, or a ';'-separated string. Semicolon only, deliberately:
     * real category names carry commas ("Steel, Metals & Metal Products"), and
     * splitting on ',' would shatter one tag into two. CSV exports use ';' as
     * the multi-value separator for exactly this reason.
     *
     * Blanks are dropped, whitespace trimmed, names truncated to the column
     * width, and duplicates removed case-insensitively.
     *
     * @param  string|array|null $raw
     * @return string[]
     */
    private function normalizeTags($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $parts = is_array($raw) ? $raw : explode(';', (string) $raw);

        $out = [];
        foreach ($parts as $part) {
            $name = trim((string) $part);
            // A fragment of stray punctuation ("," or "-") is separator debris,
            // not a tag someone meant to create.
            if ($name === '' || preg_match('/[\p{L}\p{N}]/u', $name) !== 1) {
                continue;
            }
            if (mb_strlen($name) > self::TAG_MAX_LEN) {
                $name = mb_substr($name, 0, self::TAG_MAX_LEN);
            }
            $out[mb_strtolower($name)] = $name;
        }

        return array_values($out);
    }

    /**
     * Link a contact to each named tag, creating tags that don't exist yet.
     *
     * Additive by design: this never removes existing links, so a re-import
     * cannot wipe tags applied by hand. The contact_tags UNIQUE(contact_id,
     * tag_id) makes a repeated link a no-op rather than a duplicate row.
     *
     * @param string[] $tagNames
     */
    private function applyTags(int $tenantId, int $contactId, array $tagNames): void
    {
        if ($tagNames === [] || $contactId <= 0) {
            return;
        }

        $table = db_connect()->table('contact_tags');
        $now   = date('Y-m-d H:i:s');

        foreach ($tagNames as $name) {
            $tagId = $this->resolveTagId($tenantId, $name);
            if ($tagId === null) {
                continue;
            }

            // ignore(true) is the portable form: "INSERT IGNORE" on MySQL,
            // "INSERT OR IGNORE" on the SQLite test database. The unique index
            // on (contact_id, tag_id) already encodes "link at most once".
            $table->ignore(true)->insert([
                'contact_id' => $contactId,
                'tag_id'     => $tagId,
                'created_at' => $now,
            ]);
        }
    }

    /** Get-or-create a tag by name, memoised for the life of the request. */
    private function resolveTagId(int $tenantId, string $name): ?int
    {
        $key = $tenantId . ':' . mb_strtolower($name);
        if (isset($this->tagCache[$key])) {
            return $this->tagCache[$key];
        }

        $tagModel = new TagModel();

        $row = $tagModel->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->first();

        if ($row !== null) {
            $id = (int) (is_array($row) ? $row['id'] : $row->id);
            return $this->tagCache[$key] = $id;
        }

        $id = (int) $tagModel->withoutTenantScope()->insert([
            'tenant_id' => $tenantId,
            'name'      => $name,
        ], true);

        return $id > 0 ? ($this->tagCache[$key] = $id) : null;
    }
}
