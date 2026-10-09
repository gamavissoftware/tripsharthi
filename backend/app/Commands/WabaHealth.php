<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\WabaAccountModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Asks Meta — not our own database — whether this WABA can still send and,
 * crucially, whether our app is still subscribed to its webhooks.
 *
 *   php spark waba:health
 *
 * Run this before any campaign. A campaign is only half a conversation: if the
 * webhook subscription is gone, outbound messages still send, replies still
 * reach the customer's phone, and none of them ever reach the Inbox. Nothing in
 * the app looks broken — the replies simply are not there.
 *
 * Meta can drop a subscription after sustained delivery failures, which is
 * exactly what a broken .env or an expired TLS certificate produces. Local
 * state cannot detect that; only Meta can be asked.
 *
 * Read-only. Sends nothing, writes nothing.
 */
class WabaHealth extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'waba:health';
    protected $description = 'Ask Meta whether the WABA is healthy and still subscribed to our webhook.';

    private bool $problem = false;

    public function run(array $params): int
    {
        $model    = new WabaAccountModel();
        $accounts = $model->withoutTenantScope()->where('status', 'active')->findAll();

        if ($accounts === []) {
            CLI::write('No active WABA account. Nothing can be sent until one is connected.', 'yellow');

            return EXIT_ERROR;
        }

        $version = env('META_GRAPH_VERSION', 'v22.0');

        foreach ($accounts as $account) {
            $wabaId = (string) ($account['waba_id'] ?? '');
            $name   = (string) ($account['display_name'] ?? 'unnamed');

            CLI::write("WABA {$wabaId} — {$name}", 'cyan');

            $token = $model->getDecryptedToken($account);

            if ($token === '' || $token === null) {
                $this->report('Access token', false, 'could not be decrypted — check that encryption.key matches the key that stored it');

                continue;
            }

            $this->checkSubscription($version, $wabaId, $token);
            $this->checkNumbers($version, $wabaId, $token);
        }

        CLI::newLine();

        if ($this->problem) {
            CLI::write('Problems found — see above.', 'red');

            return EXIT_ERROR;
        }

        CLI::write('WABA healthy: Meta will accept sends and deliver replies.', 'green');

        return EXIT_SUCCESS;
    }

    private function checkSubscription(string $version, string $wabaId, string $token): void
    {
        [$code, $body] = $this->get("https://graph.facebook.com/{$version}/{$wabaId}/subscribed_apps", $token);

        if ($code !== 200) {
            $this->report('Webhook subscription', false, "Meta returned HTTP {$code}: " . $this->firstError($body));

            return;
        }

        $apps = $body['data'] ?? [];

        if ($apps === []) {
            $this->report(
                'Webhook subscription',
                false,
                'NO app is subscribed to this WABA — outbound sends still work, but replies and delivery receipts will never arrive. Re-subscribe in Meta App Dashboard → WhatsApp → Configuration.'
            );

            return;
        }

        $names = [];

        foreach ($apps as $app) {
            $names[] = (string) ($app['whatsapp_business_api_data']['name'] ?? $app['id'] ?? 'unknown');
        }

        $this->report('Webhook subscription', true, 'active — ' . implode(', ', $names));
    }

    private function checkNumbers(string $version, string $wabaId, string $token): void
    {
        $fields = 'display_phone_number,quality_rating,status,name_status,messaging_limit_tier';
        [$code, $body] = $this->get("https://graph.facebook.com/{$version}/{$wabaId}/phone_numbers?fields={$fields}", $token);

        if ($code !== 200) {
            $this->report('Sending numbers', false, "Meta returned HTTP {$code}: " . $this->firstError($body));

            return;
        }

        foreach (($body['data'] ?? []) as $number) {
            $display = (string) ($number['display_phone_number'] ?? '?');
            $status  = strtoupper((string) ($number['status'] ?? 'UNKNOWN'));
            $quality = strtoupper((string) ($number['quality_rating'] ?? 'UNKNOWN'));
            $tier    = (string) ($number['messaging_limit_tier'] ?? '');

            $detail = "status={$status} quality={$quality}" . ($tier !== '' ? " limit={$tier}" : '');

            // RED quality is the last warning before Meta restricts the number.
            $ok = $status === 'CONNECTED' && $quality !== 'RED';

            if ($quality === 'RED') {
                $detail .= ' — RED means Meta is about to restrict this number. Do not run a marketing campaign until it recovers.';
            } elseif ($quality === 'YELLOW') {
                $detail .= ' — YELLOW means recent negative feedback. Send only to contacts who opted in.';
            }

            $this->report("Number {$display}", $ok, $detail);
        }
    }

    /**
     * @return array{0:int,1:array<string,mixed>}
     */
    private function get(string $url, string $token): array
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
                CURLOPT_TIMEOUT        => 20,
            ]);

            $raw  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [$code, is_string($raw) ? (json_decode($raw, true) ?? []) : []];
        } catch (Throwable $e) {
            return [0, ['error' => ['message' => $e->getMessage()]]];
        }
    }

    /**
     * @param array<string,mixed> $body
     */
    private function firstError(array $body): string
    {
        return (string) ($body['error']['message'] ?? 'no detail returned');
    }

    private function report(string $label, bool $ok, string $detail): void
    {
        if (! $ok) {
            $this->problem = true;
        }

        CLI::write(
            '  [' . ($ok ? 'OK  ' : 'FAIL') . '] ' . str_pad($label, 24) . $detail,
            $ok ? 'green' : 'red'
        );
    }
}
