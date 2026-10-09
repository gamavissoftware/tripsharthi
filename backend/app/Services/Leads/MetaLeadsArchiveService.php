<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\IntegrationModel;
use App\Models\MetaLeadEventModel;
use App\Services\Social\GraphClient;
use RuntimeException;

/**
 * Every lead Meta holds for a month, with everything the person typed —
 * and a way to bring the ones not yet in the CRM into Contacts so they can
 * be nurtured over WhatsApp.
 *
 * Meta keeps lead-ad submissions for 90 days on the form; this reads them
 * live (no copy is kept until an import), cross-checks each against the CRM
 * by WhatsApp number (or email for phone-less leads), and imports through
 * the same ContactDedupeService the webhook uses — so a lead imported here
 * and the same lead arriving by webhook later resolve to one contact.
 *
 * Importing does NOT start flows unless asked: bulk-importing a quarter of
 * old leads must not fire a welcome message per row.
 */
class MetaLeadsArchiveService
{
    private const PAGE_LIMIT = 100;
    private const MAX_PAGES  = 30;

    public function __construct(private ?GraphClient $graph = null, private ?int $now = null)
    {
        $this->graph ??= new GraphClient();
        $this->now   ??= time();
    }

    /**
     * @return array{month:string,from:string,to:string,forms:array<int,array<string,mixed>>,leads:array<int,array<string,mixed>>,counts:array<string,int>}
     */
    public function month(int $tenantId, string $month): array
    {
        [$from, $to] = self::bounds($month);
        [$pageId, $token, $integrationId] = $this->page($tenantId);

        $forms = $this->forms($pageId, $token);
        $leads = [];
        foreach ($forms as $form) {
            foreach ($this->leadsOfForm((string) $form['id'], $token, $from, $to) as $raw) {
                $leads[] = $this->shape($tenantId, $raw, $form);
            }
        }
        usort($leads, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        $counts = ['total' => count($leads), 'in_crm' => 0, 'won' => 0, 'not_in_crm' => 0, 'no_phone' => 0];
        foreach ($leads as $l) {
            if ($l['contact'] !== null) {
                $counts['in_crm']++;
                if (($l['contact']['status'] ?? '') === 'won') {
                    $counts['won']++;
                }
            } else {
                $counts['not_in_crm']++;
            }
            if ($l['wa_number'] === '') {
                $counts['no_phone']++;
            }
        }

        return [
            'month'          => $month,
            'from'           => gmdate('Y-m-d', $from),
            'to'             => gmdate('Y-m-d', $to - 1),
            'integration_id' => $integrationId,
            'forms'          => $forms,
            'leads'          => $leads,
            'counts'         => $counts,
        ];
    }

    /**
     * Bring leads into Contacts.
     *
     * @param array<int,string>|null $leadgenIds null = every lead of the month
     * @return array{inserted:int,updated:int,skipped:int,contact_ids:array<int,int>,tag:string}
     */
    public function import(int $tenantId, string $month, ?array $leadgenIds, bool $startFlows = false, string $tag = ''): array
    {
        $data = $this->month($tenantId, $month);
        $want = $leadgenIds === null ? null : array_flip(array_map('strval', $leadgenIds));
        $tag  = trim($tag) !== '' ? trim($tag) : 'Meta Ads · ' . self::label($month);

        $dedupe = new ContactDedupeService(new ContactModel(), new ContactFieldValueModel());
        $events = new MetaLeadEventModel();
        $out    = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'contact_ids' => [], 'tag' => $tag];

        foreach ($data['leads'] as $lead) {
            if ($want !== null && ! isset($want[$lead['leadgen_id']])) {
                continue;
            }
            if ($lead['wa_number'] === '' && $lead['email'] === '') {
                $out['skipped']++;
                continue;
            }
            $payload = $lead['payload'];
            $payload['tags'] = [$tag];
            LeadCustomFields::ensure($tenantId, $payload['custom_fields'] ?? [], $lead['field_data']);
            $result = $dedupe->upsert($tenantId, $payload, $startFlows);
            $out[$result['action'] === 'inserted' ? 'inserted' : 'updated']++;
            $out['contact_ids'][] = (int) $result['contact_id'];

            // Same idempotency record the webhook writes, so a late webhook
            // for this lead is recognised and not processed twice.
            try {
                $events->withoutTenantScope()->insert([
                    'tenant_id' => $tenantId, 'integration_id' => $data['integration_id'],
                    'leadgen_id' => $lead['leadgen_id'], 'status' => 'processed', 'contact_id' => (int) $result['contact_id'],
                ]);
            } catch (\Throwable $e) {
                // already known — fine
            }
        }

        log_message('info', "MetaLeadsArchiveService: tenant {$tenantId} imported {$out['inserted']}+{$out['updated']} leads for {$month} (flows " . ($startFlows ? 'on' : 'off') . ').');

        return $out;
    }

    // ── Graph ───────────────────────────────────────────────────────────

    /** @return array<int,array{id:string,name:string,status:string,leads_count:int}> */
    private function forms(string $pageId, string $token): array
    {
        $resp  = $this->graph->get("{$pageId}/leadgen_forms", ['fields' => 'id,name,status,leads_count', 'limit' => 100, 'access_token' => $token]);
        $forms = [];
        foreach ((array) ($resp['data'] ?? []) as $f) {
            $f       = (array) $f;
            $forms[] = ['id' => (string) $f['id'], 'name' => (string) ($f['name'] ?? ''), 'status' => (string) ($f['status'] ?? ''), 'leads_count' => (int) ($f['leads_count'] ?? 0)];
        }

        return $forms;
    }

    /** @return array<int,array<string,mixed>> raw Graph lead nodes created in [from, to) */
    private function leadsOfForm(string $formId, string $token, int $from, int $to): array
    {
        $fields = 'id,created_time,field_data,ad_id,ad_name,adset_name,campaign_name,platform,is_organic';
        $out    = [];
        $after  = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $params = [
                'fields'       => $fields,
                'limit'        => self::PAGE_LIMIT,
                'filtering'    => json_encode([
                    ['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $from - 1],
                    ['field' => 'time_created', 'operator' => 'LESS_THAN',    'value' => $to],
                ]),
                'access_token' => $token,
            ];
            if ($after !== null) {
                $params['after'] = $after;
            }
            try {
                $resp = $this->graph->get("{$formId}/leads", $params);
            } catch (\Throwable $e) {
                if ($fields !== 'id,created_time,field_data') {
                    // Some fields need extra permission; the answers are what matter.
                    $fields = 'id,created_time,field_data';
                    $page--;
                    continue;
                }
                throw $e;
            }
            foreach ((array) ($resp['data'] ?? []) as $lead) {
                $out[] = (array) $lead;
            }
            $after = $resp['paging']['cursors']['after'] ?? null;
            if ($after === null || ! isset($resp['paging']['next'])) {
                break;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $raw  @param array<string,mixed> $form  @return array<string,mixed> */
    private function shape(int $tenantId, array $raw, array $form): array
    {
        $fieldData   = (array) ($raw['field_data'] ?? []);
        $countryCode = '+91';
        $mapped      = MetaLeadMapper::map($fieldData, $countryCode);

        $answers = [];
        foreach ($fieldData as $f) {
            $f = (array) $f;
            $answers[] = ['question' => (string) ($f['name'] ?? ''), 'answer' => (string) (((array) ($f['values'] ?? []))[0] ?? '')];
        }

        $contact = null;
        $model   = (new ContactModel())->setTenant($tenantId);
        $row     = $mapped['wa_number'] !== '' ? $model->findByWaNumber($mapped['wa_number'])
                 : (! empty($mapped['email']) ? $model->findByEmail((string) $mapped['email']) : null);
        if ($row) {
            $row     = (array) $row;
            $contact = ['id' => (int) $row['id'], 'name' => (string) ($row['name'] ?? ''), 'status' => (string) ($row['status'] ?? ''), 'source' => (string) ($row['source'] ?? '')];
        }

        $created = (string) ($raw['created_time'] ?? '');
        $ts      = $created !== '' ? strtotime($created) : 0;

        return [
            'leadgen_id'    => (string) ($raw['id'] ?? ''),
            'created_at'    => $ts ? gmdate('Y-m-d H:i:s', $ts) : '',
            'form_id'       => (string) $form['id'],
            'form_name'     => (string) $form['name'],
            'campaign_name' => (string) ($raw['campaign_name'] ?? ''),
            'ad_name'       => (string) ($raw['ad_name'] ?? ''),
            'platform'      => (string) ($raw['platform'] ?? ''),
            'is_organic'    => (bool) ($raw['is_organic'] ?? false),
            'name'          => (string) ($mapped['name'] ?? ''),
            'wa_number'     => (string) ($mapped['wa_number'] ?? ''),
            'email'         => (string) ($mapped['email'] ?? ''),
            'company'       => (string) ($mapped['company'] ?? ''),
            'job_title'     => (string) ($mapped['job_title'] ?? ''),
            'answers'       => $answers,
            'contact'       => $contact,
            'field_data'    => $fieldData,
            'payload'       => $mapped,
        ];
    }

    /** @return array{0:string,1:string,2:int} [page_id, token, integration_id] */
    private function page(int $tenantId): array
    {
        $model = new IntegrationModel();
        $row   = $model->findActiveByType($tenantId, MetaLeadAdsLinker::TYPE);
        if (! $row) {
            throw new RuntimeException('No Facebook Page is linked for Lead Ads on this workspace.');
        }
        $row   = (array) $row;
        $token = $model->decryptPageToken($row);
        if ($token === '' || empty($row['page_id'])) {
            throw new RuntimeException('The linked Page has no usable access token — reconnect with Facebook.');
        }

        return [(string) $row['page_id'], $token, (int) $row['id']];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** [start, end) unix for a YYYY-MM month. */
    public static function bounds(string $month): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            throw new RuntimeException('Month must look like 2026-06.');
        }
        $from = (int) gmmktime(0, 0, 0, (int) $m[2], 1, (int) $m[1]);
        $to   = (int) gmmktime(0, 0, 0, (int) $m[2] + 1, 1, (int) $m[1]);

        return [$from, $to];
    }

    public static function label(string $month): string
    {
        [$from] = self::bounds($month);

        return gmdate('M Y', $from);
    }
}
