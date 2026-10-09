<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\ContactModel;
use App\Models\ConversionEventModel;
use App\Models\IntegrationModel;
use App\Models\TripModel;

/**
 * Closed-loop ad optimisation: report CRM outcomes (quote sent, booking paid)
 * back to Meta (Conversions API) and Google (offline click conversions) so the
 * platforms optimise for real bookings, not cheap form fills.
 *
 * queueForTrip() writes an OUTBOX row (idempotent on tenant+platform+event_id);
 * dispatch() delivers pending rows with retry. Never called inline from a
 * request that must stay fast: TripService only queues.
 *
 * Meta graph API versions and Google Ads API versions sunset quickly — both are
 * env-configurable (META_GRAPH_VERSION, GOOGLE_ADS_API_VERSION).
 */
final class ConversionFeedbackService
{
    /** trip status => event name sent to the platforms */
    public const EVENTS = ['enquiry' => 'Lead', 'quoted' => 'QuoteSent', 'booked' => 'Purchase'];
    private const MAX_ATTEMPTS = 5;
    /** Google rejects click IDs older than 90 days; stay well inside. */
    private const MAX_AGE_DAYS = 80;

    /** @var callable(string,string,array):array{status:int,body:string} */
    private $http;

    public function __construct(?callable $http = null)
    {
        $this->http = $http ?? [$this, 'curl'];
    }

    public function queueForTrip(int $tenantId, int $tripId, string $tripStatus, ?int $valueAmount = null): ?int
    {
        $event = self::EVENTS[$tripStatus] ?? null;
        if ($event === null) {
            return null;
        }
        $trip = (new TripModel())->setTenant($tenantId)->find($tripId);
        if (! $trip || ! $trip['contact_id']) {
            return null;
        }
        $attr = (new AttributionService())->forContact($tenantId, (int) $trip['contact_id']);
        if (! $attr || ! in_array($attr['platform'], ['meta', 'google'], true)) {
            return null;
        }
        // Google needs a click id; Meta needs one of lead_id / ctwa_clid / fbc, else hashed PII still works.
        if ($attr['platform'] === 'google' && ! ($attr['gclid'] || $attr['gbraid'] || $attr['wbraid'])) {
            return null;
        }
        $m  = new ConversionEventModel();
        $id = $m->setTenant($tenantId)->insert([
            'contact_id' => $trip['contact_id'], 'trip_id' => $tripId, 'platform' => $attr['platform'],
            'event_name' => $event, 'event_id' => "trip{$tripId}-{$event}",
            'value_amount' => (int) ($valueAmount ?? 0), 'currency' => 'INR',
            'event_time' => date('Y-m-d H:i:s'), 'status' => 'pending',
        ], true);
        return $id ? (int) $id : null; // duplicate (tenant,platform,event_id) insert fails → null, which is the idempotency we want
    }

    /** Deliver pending events across all tenants. @return array{sent:int,failed:int,skipped:int} */
    public function dispatch(int $limit = 100): array
    {
        $out  = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $rows = (new ConversionEventModel())->withoutTenantScope()->where('status', 'pending')
            ->where('attempts <', self::MAX_ATTEMPTS)->orderBy('id')->findAll($limit);
        foreach ($rows as $row) {
            $tid = (int) $row['tenant_id'];
            $upd = ['attempts' => (int) $row['attempts'] + 1];
            try {
                if (strtotime((string) $row['event_time']) < strtotime('-' . self::MAX_AGE_DAYS . ' days')) {
                    $upd += ['status' => 'skipped', 'response' => 'Older than the platform click-id window.'];
                } else {
                    $res = $row['platform'] === 'meta' ? $this->sendMeta($tid, $row) : $this->sendGoogle($tid, $row);
                    if ($res === null) {            // integration not configured: leave pending, don't burn attempts
                        $out['skipped']++;
                        continue;
                    }
                    $upd += $res;
                }
            } catch (\Throwable $e) {
                $upd += ['status' => (int) $row['attempts'] + 1 >= self::MAX_ATTEMPTS ? 'failed' : 'pending', 'response' => mb_substr($e->getMessage(), 0, 500)];
            }
            (new ConversionEventModel())->setTenant($tid)->update((int) $row['id'], $upd);
            $key = $upd['status'] ?? 'pending';
            if ($key === 'sent') { $out['sent']++; } elseif ($key === 'failed') { $out['failed']++; } elseif ($key === 'skipped') { $out['skipped']++; }
        }
        return $out;
    }

    // ---- Meta Conversions API ------------------------------------------------

    /** @return array<string,mixed>|null null = not configured */
    private function sendMeta(int $tenantId, array $row): ?array
    {
        $cfg = $this->config($tenantId, 'meta_capi');
        if (! $cfg || empty($cfg['dataset_id']) || empty($cfg['access_token'])) {
            return null;
        }
        if (! self::eventEnabled($cfg, (string) $row['event_name'])) {
            return ['status' => 'skipped', 'response' => 'Event turned off in Ad Platforms settings.'];
        }
        $attr    = (new AttributionService())->forContact($tenantId, (int) $row['contact_id']);
        $contact = (new ContactModel())->setTenant($tenantId)->find((int) $row['contact_id']);
        $payload = ['data' => [self::metaEvent($row, $attr ?? [], $contact ?? [], (string) ($cfg['waba_id'] ?? ''))]];
        if (! empty($cfg['test_event_code'])) {
            $payload['test_event_code'] = $cfg['test_event_code'];
        }
        $payload['access_token'] = $cfg['access_token'];
        $ver = (string) (env('META_GRAPH_VERSION') ?: 'v25.0');
        $res = ($this->http)('POST', "https://graph.facebook.com/{$ver}/{$cfg['dataset_id']}/events", ['json' => $payload]);
        return $this->result($res);
    }

    /** Build one CAPI event. Public static so it can be unit-tested without HTTP. */
    public static function metaEvent(array $row, array $attr, array $contact, string $wabaId = ''): array
    {
        $user = [];
        if (! empty($contact['wa_number'])) {
            $user['ph'] = [hash('sha256', preg_replace('/\D+/', '', (string) $contact['wa_number']))];
        }
        if (! empty($contact['email'])) {
            $user['em'] = [hash('sha256', strtolower(trim((string) $contact['email'])))];
        }
        if (! empty($attr['fbc'])) { $user['fbc'] = $attr['fbc']; }
        if (! empty($attr['fbp'])) { $user['fbp'] = $attr['fbp']; }

        $ev = [
            'event_name' => $row['event_name'],
            'event_time' => strtotime((string) $row['event_time']),
            'event_id'   => $row['event_id'],
            'custom_data' => ['currency' => $row['currency'], 'value' => round(((int) $row['value_amount']) / 100, 2)],
        ];
        if (! empty($attr['ctwa_clid'])) {
            $ev['action_source']   = 'business_messaging';
            $ev['messaging_channel'] = 'whatsapp';
            $user['ctwa_clid'] = $attr['ctwa_clid'];
            if ($wabaId !== '') { $user['whatsapp_business_account_id'] = $wabaId; }
        } elseif (! empty($attr['lead_id'])) {
            // Conversion Leads: instant-form lead id + CRM event source.
            $ev['action_source'] = 'system_generated';
            $user['lead_id'] = (int) $attr['lead_id'];
            $ev['custom_data'] += ['event_source' => 'crm', 'lead_event_source' => 'TravelPilot'];
        } else {
            $ev['action_source'] = 'website';
        }
        $ev['user_data'] = $user;
        return $ev;
    }

    // ---- Google offline conversions ----------------------------------------

    private function sendGoogle(int $tenantId, array $row): ?array
    {
        $cfg = $this->config($tenantId, 'google_ads');
        if ($cfg && ! self::eventEnabled($cfg, (string) $row['event_name'])) {
            return ['status' => 'skipped', 'response' => 'Event turned off in Ad Platforms settings.'];
        }
        $action = $cfg['conversion_actions'][$row['event_name']] ?? null;
        if (! $cfg || empty($cfg['customer_id']) || empty($cfg['refresh_token']) || ! $action) {
            return null;
        }
        $attr = (new AttributionService())->forContact($tenantId, (int) $row['contact_id']) ?? [];
        $conv = self::googleConversion($row, $attr, (string) $action);
        if ($conv === null) {
            return ['status' => 'skipped', 'response' => 'No gclid/gbraid/wbraid on this lead.'];
        }
        $tok = ($this->http)('POST', 'https://oauth2.googleapis.com/token', ['form_params' => [
            'client_id' => env('GOOGLE_ADS_CLIENT_ID'), 'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
            'refresh_token' => $cfg['refresh_token'], 'grant_type' => 'refresh_token',
        ]]);
        $access = json_decode($tok['body'], true)['access_token'] ?? null;
        if (! $access) {
            throw new \RuntimeException('Google token refresh failed: ' . mb_substr($tok['body'], 0, 200));
        }
        $headers = ['Authorization' => "Bearer {$access}"];
        if ($dev = env('GOOGLE_ADS_DEVELOPER_TOKEN')) { $headers['developer-token'] = $dev; }
        if (! empty($cfg['login_customer_id'])) { $headers['login-customer-id'] = preg_replace('/\D/', '', (string) $cfg['login_customer_id']); }
        $ver = (string) (env('GOOGLE_ADS_API_VERSION') ?: 'v25');
        $cid = preg_replace('/\D/', '', (string) $cfg['customer_id']);
        $res = ($this->http)('POST', "https://googleads.googleapis.com/{$ver}/customers/{$cid}:uploadClickConversions", [
            'headers' => $headers, 'json' => ['conversions' => [$conv], 'partialFailure' => true],
        ]);
        $r = $this->result($res);
        // partialFailure returns 200 with an error block per row.
        if ($r['status'] === 'sent' && ! empty(json_decode($res['body'], true)['partialFailureError'])) {
            return ['status' => 'failed', 'response' => mb_substr($res['body'], 0, 500)];
        }
        return $r;
    }

    public static function googleConversion(array $row, array $attr, string $conversionAction): ?array
    {
        $click = [];
        if (! empty($attr['gclid'])) { $click['gclid'] = $attr['gclid']; }
        elseif (! empty($attr['gbraid'])) { $click['gbraid'] = $attr['gbraid']; }
        elseif (! empty($attr['wbraid'])) { $click['wbraid'] = $attr['wbraid']; }
        else { return null; }
        // Google wants "yyyy-mm-dd hh:mm:ss+|-hh:mm" — IST, never a bare UTC string.
        $when = (new \DateTimeImmutable('@' . strtotime((string) $row['event_time'])))
            ->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i:sP');
        return $click + [
            'conversionAction'   => $conversionAction,
            'conversionDateTime' => $when,
            'conversionValue'    => round(((int) $row['value_amount']) / 100, 2),
            'currencyCode'       => $row['currency'],
            'orderId'            => $row['event_id'],
        ];
    }

    /** `events` is an optional allow-list in the integration config; absent = all events. */
    public static function eventEnabled(array $cfg, string $event): bool
    {
        return ! isset($cfg['events']) || ! is_array($cfg['events']) || in_array($event, $cfg['events'], true);
    }

    // ---- plumbing ---------------------------------------------------------

    private function result(array $res): array
    {
        $ok = $res['status'] >= 200 && $res['status'] < 300;
        return ['status' => $ok ? 'sent' : 'pending', 'response' => mb_substr($res['body'], 0, 500), 'sent_at' => $ok ? date('Y-m-d H:i:s') : null]
            + ($ok ? [] : ['status' => $res['status'] >= 400 && $res['status'] < 500 && $res['status'] !== 429 ? 'failed' : 'pending']);
    }

    /** Decrypted integration config, or null. Secrets are stored encrypted under *_enc keys. */
    public function config(int $tenantId, string $type): ?array
    {
        $im  = new IntegrationModel();
        $row = $im->findActiveByType($tenantId, $type);
        if (! $row) {
            return null;
        }
        $cfg = json_decode((string) (is_array($row) ? $row['config'] : $row->config), true) ?: [];
        foreach ($cfg as $k => $v) {
            if (str_ends_with((string) $k, '_enc') && is_string($v) && $v !== '') {
                $cfg[substr($k, 0, -4)] = \App\Services\WhatsApp\TokenCipher::decrypt($v);
            }
        }
        return $cfg;
    }

    public static function defaultHttp(string $method, string $url, array $opt): array
    {
        return (new self())->curl($method, $url, $opt);
    }

    private function curl(string $method, string $url, array $opt): array
    {
        $client = service('curlrequest');
        $r = $client->request($method, $url, $opt + ['http_errors' => false, 'timeout' => 20]);
        return ['status' => $r->getStatusCode(), 'body' => (string) $r->getBody()];
    }
}
