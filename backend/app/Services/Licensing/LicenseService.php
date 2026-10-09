<?php

declare(strict_types=1);

namespace App\Services\Licensing;

/**
 * License key management for self_hosted mode.
 *
 * ── Three entry points, each with a different network posture ────────────
 *
 *   activate()      — SYNCHRONOUS phone-home.  Called once by the user during
 *                     setup.  Acceptable latency; first activation cannot be
 *                     offline (we need Gamavis to record the activation).
 *
 *   checkStatus()   — PURE DB READ.  No network, no curl.  Called on every
 *                     request via LicenseFilter.  Returns cached status from
 *                     the licenses table.  Fast, safe for hot path.
 *
 *   runPhoneHome()  — NETWORK CALL.  Called ONLY from the license:check cron
 *                     (daily).  Updates last_check_at/status in DB.  Never
 *                     called from a request.
 *
 * ── Grace period logic (in runPhoneHome, not in checkStatus) ─────────────
 * last_check_at = last SUCCESSFUL phone-home timestamp.
 * If phone-home fails and (now - last_check_at) ≤ GRACE_PERIOD_DAYS → warn, stay active.
 * If phone-home fails and (now - last_check_at) > GRACE_PERIOD_DAYS → status='grace' (read-only).
 * checkStatus() reads status from DB — it does not recompute grace arithmetic.
 *
 * ── Injectable HTTP client ────────────────────────────────────────────────
 * Pass a \Closure $httpClient to the constructor to mock phone-home in tests
 * without subclassing.  The closure receives the raw key and must return
 * array{'status': string}.
 */
class LicenseService
{
    /** Days of failed phone-home before read-only mode activates. */
    public const GRACE_PERIOD_DAYS = 7;

    /** How often the cron should run phone-home (informational — enforced in cron schedule). */
    public const RECHECK_INTERVAL_HOURS = 24;

    public function __construct(
        private readonly ?\Closure        $httpClient  = null,
        private readonly ?LicenseJwtVerifier $jwtVerifier = null,
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Read cached license status from the DB.
     * NO NETWORK CALL — safe for the request hot path (LicenseFilter).
     */
    public function checkStatus(): LicenseStatus
    {
        if ($this->isMockMode()) {
            return LicenseStatus::active();
        }

        $row = $this->loadRow();
        if ($row === null) {
            return LicenseStatus::readOnly('no_license_row');
        }

        // Re-verify the key signature offline on every check (cheap HMAC, no network).
        if (! ($this->jwtVerifier ?? new LicenseJwtVerifier())->verify($row['key_payload'])) {
            return LicenseStatus::readOnly('invalid_key_signature');
        }

        return match ($row['status']) {
            'active'   => LicenseStatus::active(),
            'grace'    => $this->resolveGraceStatus($row),
            'inactive' => LicenseStatus::readOnly('license_inactive'),
            default    => LicenseStatus::readOnly('unknown_status'),
        };
    }

    /**
     * First-time activation.  Requires network (Gamavis must record it).
     * Idempotent — re-activating the same key updates last_check_at.
     *
     * @throws \InvalidArgumentException  Key signature is invalid.
     * @throws \RuntimeException          Phone-home failed or key is not active.
     */
    public function activate(string $rawKey): void
    {
        $verifier = $this->jwtVerifier ?? new LicenseJwtVerifier();

        if (! $this->isMockMode() && ! $verifier->verify($rawKey)) {
            throw new \InvalidArgumentException(
                'License key signature is invalid. '
                . 'Ensure you are using the key exactly as provided by Gamavis.'
            );
        }

        if (! $this->isMockMode()) {
            $result = $this->doPhoneHome($rawKey);
            $status = $result['status'] ?? '';

            if ($status !== 'active') {
                $msg = $result['message'] ?? "status={$status}";
                throw new \RuntimeException("License is not active: {$msg}");
            }
        }

        $payload      = $verifier->decode($rawKey);
        $keyHash      = hash('sha256', $rawKey);
        $db           = db_connect();
        $existing     = $db->table('licenses')->where('key_hash', $keyHash)->get()->getRowArray();

        $data = [
            'key_hash'          => $keyHash,
            'key_payload'       => $rawKey,
            'customer_email'    => $payload['email'] ?? null,
            'activated_at'      => date('Y-m-d H:i:s'),
            'last_check_at'     => date('Y-m-d H:i:s'),
            'last_check_status' => 'ok',
            'status'            => 'active',
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->table('licenses')->insert($data);
        } else {
            $db->table('licenses')->where('key_hash', $keyHash)->update($data);
        }
    }

    /**
     * Phone-home to Gamavis and update DB accordingly.
     * Called ONLY from the license:check cron — never from a request.
     */
    public function runPhoneHome(): void
    {
        if ($this->isMockMode()) {
            log_message('alert',
                'LicenseService::runPhoneHome: LICENSE_MOCK_MODE enabled — '
                . 'skipping phone-home. DO NOT use in production.'
            );
            return;
        }

        $row = $this->loadRow();
        if ($row === null) {
            log_message('warning', 'license:check: no license row found — run license:activate first.');
            return;
        }

        try {
            $result = $this->doPhoneHome($row['key_payload']);
            $status = $result['status'] ?? 'unknown';

            if ($status === 'active') {
                $this->writeCheck($row['id'], 'ok', 'active');
                log_message('info', 'license:check: license confirmed active.');
                return;
            }

            if (in_array($status, ['revoked', 'expired'], true)) {
                db_connect()->table('licenses')->where('id', $row['id'])->update([
                    'last_check_status' => 'failed',
                    'status'            => 'inactive',
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]);
                log_message('error', "license:check: Gamavis returned status={$status} — marking inactive.");
                return;
            }

            // Unexpected status — treat as a phone-home failure
            throw new \RuntimeException("Unexpected status from Gamavis: {$status}");

        } catch (\Throwable $e) {
            $this->handlePhoneHomeFail($row, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function resolveGraceStatus(array $row): LicenseStatus
    {
        $lastOk   = $row['last_check_at'] ? strtotime($row['last_check_at']) : 0;
        $daysOld  = $lastOk > 0 ? (int) floor((time() - $lastOk) / 86400) : PHP_INT_MAX;
        $daysLeft = self::GRACE_PERIOD_DAYS - $daysOld;

        return $daysLeft > 0
            ? LicenseStatus::withinGrace($daysLeft)
            : LicenseStatus::graceExpired();
    }

    private function handlePhoneHomeFail(array $row, string $reason): void
    {
        $lastOk   = $row['last_check_at'] ? strtotime($row['last_check_at']) : 0;
        $daysOld  = $lastOk > 0 ? (int) floor((time() - $lastOk) / 86400) : PHP_INT_MAX;
        $daysLeft = self::GRACE_PERIOD_DAYS - $daysOld;

        if ($daysLeft <= 0) {
            // Grace expired — flip to grace (read-only)
            db_connect()->table('licenses')->where('id', $row['id'])->update([
                'last_check_status' => 'failed',
                'status'            => 'grace',
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);
            log_message('error',
                "license:check: phone-home failed and grace period expired ({$reason}). "
                . 'System entering read-only mode. Contact support@gamavis.com.'
            );
        } else {
            // Within grace — update failure flag, keep status='active'
            db_connect()->table('licenses')->where('id', $row['id'])->update([
                'last_check_status' => 'failed',
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);
            log_message('warning',
                "license:check: phone-home failed, {$daysLeft} grace day(s) remaining. "
                . "System continues normally. Reason: {$reason}"
            );
        }
    }

    private function writeCheck(int $id, string $checkStatus, string $rowStatus): void
    {
        $data = [
            'last_check_status' => $checkStatus,
            'status'            => $rowStatus,
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
        if ($checkStatus === 'ok') {
            $data['last_check_at'] = date('Y-m-d H:i:s'); // only updated on success
        }
        db_connect()->table('licenses')->where('id', $id)->update($data);
    }

    private function loadRow(): ?array
    {
        $row = db_connect()->table('licenses')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        return $row ?: null;
    }

    private function doPhoneHome(string $rawKey): array
    {
        if ($this->httpClient !== null) {
            return ($this->httpClient)($rawKey);
        }

        $url = env('LICENSE_VALIDATE_URL', 'https://license.gamavis.com/validate');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => 5000,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['key' => $rawKey]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200) {
            throw new \RuntimeException(
                'Gamavis license endpoint unreachable: ' . ($err ?: "HTTP {$code}")
            );
        }

        return json_decode($resp, true) ?? [];
    }

    private function isMockMode(): bool
    {
        return filter_var(env('LICENSE_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }
}
