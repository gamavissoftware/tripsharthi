<?php

declare(strict_types=1);

namespace App\Services\Partner;

use App\Services\Email\EmailService;
use App\Services\WhatsApp\TokenCipher;

/**
 * The referral partner program.
 *
 *  - A partner has a public referral CODE. A workspace that signs up through it (`ref`) is attributed to the partner (first touch wins,
 *    never overwritten, never to a partner's own email); a brand-new customer who pays with a partner's COUPON is attributed at purchase.
 *  - Every VERIFIED paid payment of a referred workspace earns the partner `commission_pct` of what the customer actually paid (after
 *    discounts), for `commission_months` after the first commission (0 = lifetime). One commission per payment (UNIQUE subscription_id).
 *  - A commission is `pending` until `payable_after` (hold, default 30 days - the refund window) passes; then it is AVAILABLE. A payout
 *    settles everything available at that moment. A commission can be voided until it is paid.
 *  - Partners never see customers' contact details - only workspace name, plan state and the money.
 *
 * Exceptions: \InvalidArgumentException = bad input (422), \OutOfBoundsException = not found (404), \DomainException = not allowed now (409).
 * Amounts are integer PAISE. Not handled (needs the CA): TDS on commission, GST on partner invoices, clawback of an already-paid commission.
 */
final class PartnerService
{
    public const DEFAULT_PCT       = 20.0;
    public const DEFAULT_MONTHS    = 12;
    public const DEFAULT_HOLD_DAYS = 30;
    public const MIN_PAYOUT_PAISE  = 100_000;      // Rs 1,000: below this a payout waits unless the admin overrides
    public const INVITE_DAYS       = 7;
    public const MIN_PASSWORD      = 10;

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? $this->ts()); }

    public static function normalizeCode(string $c): string { return strtoupper(trim($c)); }
    public static function isValidCode(string $c): bool { return (bool) preg_match('/^[A-Z0-9][A-Z0-9_-]{2,19}$/', $c); }

    // ================================================================================================================================
    // partner accounts (admin side)
    // ================================================================================================================================

    /** @return array{partner:array,invite_token:string} the raw invite token is shown ONCE (the admin shares the link) */
    public function create(array $in, int $adminId): array
    {
        $db = db_connect();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) { throw new \InvalidArgumentException('Enter the partner\'s name.'); }
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) { throw new \InvalidArgumentException('Enter a valid email address - it is the partner\'s login.'); }
        if ($db->table('partners')->where('email', $email)->countAllResults() > 0) { throw new \DomainException('A partner with this email already exists.'); }
        $terms = $this->terms($in, null);
        $code  = trim((string) ($in['code'] ?? '')) === '' ? $this->freshCode($name) : self::normalizeCode((string) $in['code']);
        if (! self::isValidCode($code)) { throw new \InvalidArgumentException('A code is 3-20 characters: letters, numbers, - or _.'); }
        if ($db->table('partners')->where('code', $code)->countAllResults() > 0 || $db->table('coupons')->where('code', $code)->countAllResults() > 0) { throw new \DomainException("The code {$code} is already taken."); }

        [$raw, $hash] = $this->newInviteToken();
        $db->table('partners')->insert($terms + ['name' => $name, 'company' => mb_substr(trim((string) ($in['company'] ?? '')), 0, 160) ?: null, 'email' => $email,
            'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 30) ?: null, 'code' => $code, 'status' => 'invited', 'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000) ?: null,
            'invite_token_hash' => $hash, 'invite_expires_at' => $this->stamp($this->ts() + self::INVITE_DAYS * 86400), 'created_by' => $adminId, 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        $id = (int) $db->insertID();
        $this->log($id, 'admin', $adminId, 'partner.created', "code {$code}");
        return ['partner' => $this->adminRow($id), 'invite_token' => $raw];
    }

    public function update(int $id, array $in, int $adminId): array
    {
        $p = $this->row($id);
        $row = $this->terms($in, $p);
        foreach (['name' => 120, 'company' => 160, 'phone' => 30] as $f => $max) {
            if (array_key_exists($f, $in)) {
                $v = trim((string) $in[$f]);
                if ($f === 'name' && ($v === '' || mb_strlen($v) > $max)) { throw new \InvalidArgumentException('Enter the partner\'s name.'); }
                $row[$f] = mb_substr($v, 0, $max) ?: null;
            }
        }
        if (array_key_exists('notes', $in)) { $row['notes'] = mb_substr(trim((string) $in['notes']), 0, 2000) ?: null; }
        $row['updated_at'] = $this->stamp();
        db_connect()->table('partners')->where('id', $id)->update($row);
        $this->log($id, 'admin', $adminId, 'partner.updated', json_encode(array_diff_key($row, ['updated_at' => 1, 'notes' => 1])) ?: null);
        return $this->adminRow($id);
    }

    public function setStatus(int $id, string $status, int $adminId): array
    {
        $p = $this->row($id);
        if (! in_array($status, ['active', 'suspended'], true)) { throw new \InvalidArgumentException('Choose active or suspended.'); }
        if ($p['status'] === 'invited' && $status === 'active') { throw new \DomainException('This partner has not accepted the invite yet.'); }
        db_connect()->table('partners')->where('id', $id)->update(['status' => $status, 'updated_at' => $this->stamp()]);
        if ($status === 'suspended') { (new PartnerSessionService($this->now))->revokeAll($id); }
        $this->log($id, 'admin', $adminId, 'partner.' . $status);
        return $this->adminRow($id);
    }

    /** A fresh one-time link (also how a forgotten password is reset). The old password keeps working until the new one is set. */
    public function newInvite(int $id, int $adminId): string
    {
        $this->row($id);
        [$raw, $hash] = $this->newInviteToken();
        db_connect()->table('partners')->where('id', $id)->update(['invite_token_hash' => $hash, 'invite_expires_at' => $this->stamp($this->ts() + self::INVITE_DAYS * 86400), 'updated_at' => $this->stamp()]);
        $this->log($id, 'admin', $adminId, 'partner.invite_issued');
        return $raw;
    }

    /** @return array{name:string,email:string,has_phone:bool} for the set-password screen */
    public function inviteInfo(string $raw): array
    {
        $p = $this->byInvite($raw);
        return ['name' => $p['name'], 'email' => $p['email'], 'has_phone' => \App\Services\Marketing\PlatformConsent::normalizePhone($p['phone']) !== null];
    }

    /** Sets the password from an invite link, activates an invited partner and ends their old sessions. */
    public function acceptInvite(string $raw, string $password, string $ip = '', bool $waOptIn = false): array
    {
        $p = $this->byInvite($raw);
        if (mb_strlen($password) < self::MIN_PASSWORD || strlen($password) > 200) { throw new \InvalidArgumentException('Use a password of at least ' . self::MIN_PASSWORD . ' characters.'); }
        db_connect()->table('partners')->where('id', (int) $p['id'])->update(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'invite_token_hash' => null, 'invite_expires_at' => null,
            'status' => $p['status'] === 'invited' ? 'active' : $p['status'], 'updated_at' => $this->stamp()]);
        (new PartnerSessionService($this->now))->revokeAll((int) $p['id']);
        $this->log((int) $p['id'], 'partner', (int) $p['id'], 'password.set', null, $ip);
        if ($waOptIn && \App\Services\Marketing\PlatformConsent::normalizePhone($p['phone']) !== null) {     // only ever SET here: a reset link never silently withdraws a yes
            db_connect()->table('partners')->where('id', (int) $p['id'])->update(['wa_marketing_opt_in' => 1]);
            $this->log((int) $p['id'], 'partner', (int) $p['id'], 'whatsapp_consent.given', 'at invite', $ip);
        }
        return $this->row((int) $p['id']);
    }

    /** The partner when email + password are right and the account is active; otherwise null (callers never say which part failed). */
    public function verifyLogin(string $email, string $password, string $ip = ''): ?array
    {
        $p = db_connect()->table('partners')->where('email', strtolower(trim($email)))->get()->getRowArray();
        $ok = password_verify($password, $p['password_hash'] ?? '$2y$10$.JUJsn0BkVTCnjk5Cmd0zeIQSxTL85G1RftwZ4k5Cbf5/Vu3X7VfC') && $p !== null && $p['password_hash'] !== null && $p['status'] === 'active';
        if (! $ok) { if ($p) { $this->log((int) $p['id'], 'partner', (int) $p['id'], 'login.failed', null, $ip); } return null; }
        db_connect()->table('partners')->where('id', (int) $p['id'])->update(['last_login_at' => $this->stamp()]);
        $this->log((int) $p['id'], 'partner', (int) $p['id'], 'login', null, $ip);
        return $p;
    }

    public function changePassword(int $partnerId, string $current, string $new, string $ip = ''): void
    {
        $p = $this->row($partnerId);
        if (! password_verify($current, (string) $p['password_hash'])) { throw new \DomainException('Your current password is not right.'); }
        if (mb_strlen($new) < self::MIN_PASSWORD || strlen($new) > 200) { throw new \InvalidArgumentException('Use a password of at least ' . self::MIN_PASSWORD . ' characters.'); }
        db_connect()->table('partners')->where('id', $partnerId)->update(['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'updated_at' => $this->stamp()]);
        $this->log($partnerId, 'partner', $partnerId, 'password.changed', null, $ip);
    }

    /** The partner's own WhatsApp number + whether they agreed to hear from TripSarthi about the program on WhatsApp. */
    public function savePreferences(int $partnerId, ?string $phone, ?bool $optIn, string $ip = ''): array
    {
        $p = $this->row($partnerId);
        $f = \App\Services\Marketing\PlatformConsent::fields($phone !== null ? $phone : (string) $p['phone'], $optIn ?? (bool) $p['wa_marketing_opt_in'], $this->ts());
        db_connect()->table('partners')->where('id', $partnerId)->update(['phone' => $f['phone'], 'wa_marketing_opt_in' => $f['wa_marketing_opt_in'], 'updated_at' => $this->stamp()]);
        $this->log($partnerId, 'partner', $partnerId, $f['wa_marketing_opt_in'] === 1 ? 'whatsapp_consent.given' : 'whatsapp_consent.withdrawn', null, $ip);
        return ['phone' => $f['phone'], 'wa_marketing_opt_in' => $f['wa_marketing_opt_in'] === 1];
    }

    // ================================================================================================================================
    // attribution
    // ================================================================================================================================

    public function findActiveByCode(string $code): ?array
    {
        $code = self::normalizeCode($code);
        if (! self::isValidCode($code)) { return null; }
        $p = db_connect()->table('partners')->where('code', $code)->where('status', 'active')->get()->getRowArray();
        return $p ?: null;
    }

    /** Signup through a referral link. False (silently) for an unknown/inactive code, the partner's own email, or an already-attributed workspace. */
    public function attributeSignup(int $tenantId, string $code, string $ownerEmail): bool
    {
        $p = $this->findActiveByCode($code);
        if (! $p || $this->sameMailbox($p['email'], $ownerEmail)) { return false; }
        $db = db_connect();
        $db->table('tenants')->where('id', $tenantId)->where('referred_partner_id', null)->update(['referred_partner_id' => (int) $p['id'], 'referred_at' => $this->stamp(), 'referral_source' => 'link']);
        return $db->affectedRows() > 0;
    }

    /** A brand-new customer who pays with a partner's coupon. Refused for a workspace that already has a referrer or already paid before. */
    public function attributeFromCoupon(int $tenantId, int $partnerId, int $excludeSubscriptionId = 0): bool
    {
        $db = db_connect();
        $p = $db->table('partners')->where('id', $partnerId)->where('status', 'active')->get()->getRowArray();
        if (! $p) { return false; }
        $paidBefore = $db->table('subscriptions')->where('tenant_id', $tenantId)->whereIn('status', ['active', 'cancelled', 'halted', 'downgraded'])->where('amount_paise >', 0)
            ->where('razorpay_sub_id NOT LIKE', 'admin-%')->where('id !=', $excludeSubscriptionId)->countAllResults() > 0;
        if ($paidBefore) { return false; }
        $owner = $db->table('users')->select('email')->where('tenant_id', $tenantId)->where('role', 'owner')->orderBy('id')->get()->getRowArray();
        if ($owner && $this->sameMailbox($p['email'], $owner['email'])) { return false; }
        $db->table('tenants')->where('id', $tenantId)->where('referred_partner_id', null)->update(['referred_partner_id' => $partnerId, 'referred_at' => $this->stamp(), 'referral_source' => 'coupon']);
        return $db->affectedRows() > 0;
    }

    /** Same person's mailbox: case-insensitive, and "name+tag@" counts as "name@". */
    private function sameMailbox(string $a, string $b): bool
    {
        $norm = static function (string $e): string { [$l, $d] = array_pad(explode('@', strtolower(trim($e)), 2), 2, ''); return explode('+', $l)[0] . '@' . $d; };
        return $norm($a) === $norm($b);
    }

    // ================================================================================================================================
    // commissions
    // ================================================================================================================================

    /**
     * Called after a payment is VERIFIED (BillingService::verifyPayment). Returns the commission id, or null when none is due.
     * Idempotent: the same subscription row never earns twice.
     */
    public function recordPayment(int $tenantId, int $subscriptionId, int $amountPaise, string $plan, string $cycle): ?int
    {
        $db = db_connect();
        if ($amountPaise <= 0) { return null; }
        $t = $db->table('tenants')->where('id', $tenantId)->get()->getRowArray();
        if (! $t) { return null; }
        $partnerId = (int) ($t['referred_partner_id'] ?? 0);
        if ($partnerId === 0) {
            $red = $db->table('coupon_redemptions')->where('tenant_id', $tenantId)->where('subscription_id', $subscriptionId)->where('status', 'applied')->where('partner_id IS NOT NULL', null, false)->get()->getRowArray();
            if ($red && $this->attributeFromCoupon($tenantId, (int) $red['partner_id'], $subscriptionId)) { $partnerId = (int) $red['partner_id']; }
        }
        if ($partnerId === 0) { return null; }
        $p = $db->table('partners')->where('id', $partnerId)->get()->getRowArray();
        if (! $p || $p['status'] !== 'active') { return null; }
        $existing = $db->table('partner_commissions')->select('id')->where('subscription_id', $subscriptionId)->get()->getRowArray();
        if ($existing) { return (int) $existing['id']; }

        $first = $db->query('SELECT MIN(earned_at) m FROM partner_commissions WHERE partner_id = ? AND tenant_id = ?', [$partnerId, $tenantId])->getRowArray()['m'] ?? null;
        if ($first !== null && (int) $p['commission_months'] > 0 && $this->ts() > strtotime('+' . (int) $p['commission_months'] . ' months', strtotime($first))) { return null; }
        $amount = (int) round($amountPaise * (float) $p['commission_pct'] / 100);
        if ($amount <= 0) { return null; }
        $db->table('partner_commissions')->insert(['partner_id' => $partnerId, 'tenant_id' => $tenantId, 'subscription_id' => $subscriptionId, 'plan' => $plan, 'cycle' => $cycle, 'base_paise' => $amountPaise,
            'rate_pct' => $p['commission_pct'], 'amount_paise' => $amount, 'status' => 'pending', 'earned_at' => $this->stamp(), 'payable_after' => $this->stamp($this->ts() + (int) $p['hold_days'] * 86400), 'created_at' => $this->stamp()]);
        return (int) $db->insertID();
    }

    /** Cancels a commission that has not been paid out yet (refund, chargeback, fraud). */
    public function voidCommission(int $commissionId, string $reason, int $adminId): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 300) { throw new \InvalidArgumentException('Give a reason (5-300 characters).'); }
        $db = db_connect();
        $c = $db->table('partner_commissions')->where('id', $commissionId)->get()->getRowArray() ?: throw new \OutOfBoundsException('Commission not found.');
        if ($c['status'] === 'paid') { throw new \DomainException('This commission was already paid out. Settle it with the partner by hand (clawback is not automated).'); }
        if ($c['status'] === 'void') { throw new \DomainException('This commission is already void.'); }
        $db->table('partner_commissions')->where('id', $commissionId)->update(['status' => 'void', 'void_reason' => $reason, 'voided_at' => $this->stamp()]);
        $this->log((int) $c['partner_id'], 'admin', $adminId, 'commission.void', "#{$commissionId}: {$reason}");
    }

    /** @return array{pending_paise:int,available_paise:int,paid_paise:int,void_paise:int,earned_paise:int} pending EXCLUDES what is already available */
    public function balances(int $partnerId): array
    {
        $r = db_connect()->query("SELECT COALESCE(SUM(CASE WHEN status = 'pending' AND payable_after > ? THEN amount_paise END), 0) pending,
                                         COALESCE(SUM(CASE WHEN status = 'pending' AND payable_after <= ? THEN amount_paise END), 0) available,
                                         COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_paise END), 0) paid,
                                         COALESCE(SUM(CASE WHEN status = 'void' THEN amount_paise END), 0) voided
                                    FROM partner_commissions WHERE partner_id = ?", [$this->stamp(), $this->stamp(), $partnerId])->getRowArray();
        return ['pending_paise' => (int) $r['pending'], 'available_paise' => (int) $r['available'], 'paid_paise' => (int) $r['paid'], 'void_paise' => (int) $r['voided'],
                'earned_paise' => (int) $r['pending'] + (int) $r['available'] + (int) $r['paid']];
    }

    /**
     * Records money SENT to a partner and settles every commission available right now. The admin makes the transfer (UPI/bank) first, then
     * records it here with the bank reference. Refused below the minimum unless $allowBelowMinimum.
     * @return array{payout:array,balances:array}
     */
    public function payout(int $partnerId, string $method, string $reference, string $note, int $adminId, bool $allowBelowMinimum = false): array
    {
        $p = $this->row($partnerId);
        if (! in_array($method, ['upi', 'bank', 'other'], true)) { throw new \InvalidArgumentException('Choose UPI, bank transfer or other.'); }
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 100) { throw new \InvalidArgumentException('Enter the bank / UPI reference of the transfer (up to 100 characters).'); }
        $db = db_connect();
        $db->transStart();
        $rows = $db->query("SELECT id, amount_paise FROM partner_commissions WHERE partner_id = ? AND status = 'pending' AND payable_after <= ? FOR UPDATE", [$partnerId, $this->stamp()])->getResultArray();
        $total = array_sum(array_map(fn ($r) => (int) $r['amount_paise'], $rows));
        if ($total <= 0) { $db->transComplete(); throw new \DomainException('Nothing is available to pay yet (commissions become payable after the ' . $p['hold_days'] . '-day hold).'); }
        if ($total < self::MIN_PAYOUT_PAISE && ! $allowBelowMinimum) {
            $db->transComplete();
            throw new \DomainException('Only ₹' . number_format($total / 100, 2) . ' is available, below the ₹' . number_format(self::MIN_PAYOUT_PAISE / 100) . ' minimum payout. Tick "pay anyway" to override.');
        }
        $db->table('partner_payouts')->insert(['partner_id' => $partnerId, 'amount_paise' => $total, 'method' => $method, 'reference' => $reference, 'note' => mb_substr(trim($note), 0, 300) ?: null,
            'commissions' => count($rows), 'paid_at' => $this->stamp(), 'created_by' => $adminId, 'created_at' => $this->stamp()]);
        $payoutId = (int) $db->insertID();
        $db->table('partner_commissions')->whereIn('id', array_map(fn ($r) => (int) $r['id'], $rows))->update(['status' => 'paid', 'payout_id' => $payoutId]);
        $db->transComplete();
        if (! $db->transStatus()) { throw new \RuntimeException('Could not record the payout.'); }
        $this->log($partnerId, 'admin', $adminId, 'payout.recorded', '₹' . number_format($total / 100, 2) . " ref {$reference}");
        return ['payout' => $db->table('partner_payouts')->where('id', $payoutId)->get()->getRowArray(), 'balances' => $this->balances($partnerId)];
    }

    // ================================================================================================================================
    // payout details (encrypted at rest)
    // ================================================================================================================================

    /** Changing where the money goes needs the current password (account-takeover guard) and is logged. */
    public function savePayoutDetails(int $partnerId, array $in, string $currentPassword, string $ip = ''): array
    {
        $p = $this->row($partnerId);
        if (! password_verify($currentPassword, (string) $p['password_hash'])) { throw new \DomainException('Your current password is not right.'); }
        $method = (string) ($in['method'] ?? '');
        $d = ['method' => $method];
        if ($method === 'upi') {
            $upi = strtolower(trim((string) ($in['upi'] ?? '')));
            if (! preg_match('/^[a-z0-9._-]{2,60}@[a-z][a-z0-9]{1,30}$/', $upi)) { throw new \InvalidArgumentException('Enter a valid UPI ID, like name@bank.'); }
            $d['upi'] = $upi;
        } elseif ($method === 'bank') {
            $name = trim((string) ($in['account_name'] ?? '')); $no = preg_replace('/\s+/', '', (string) ($in['account_no'] ?? '')); $ifsc = strtoupper(trim((string) ($in['ifsc'] ?? '')));
            if ($name === '' || mb_strlen($name) > 120) { throw new \InvalidArgumentException('Enter the account holder\'s name.'); }
            if (! preg_match('/^\d{6,20}$/', (string) $no)) { throw new \InvalidArgumentException('Enter the account number (digits only).'); }
            if (! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) { throw new \InvalidArgumentException('Enter a valid IFSC code, like HDFC0001234.'); }
            $d += ['account_name' => $name, 'account_no' => $no, 'ifsc' => $ifsc];
        } else { throw new \InvalidArgumentException('Choose UPI or bank account.'); }
        $pan = strtoupper(trim((string) ($in['pan'] ?? '')));
        if ($pan !== '' && ! preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $pan)) { throw new \InvalidArgumentException('Enter a valid PAN (it is needed for TDS), like ABCDE1234F.'); }
        if ($pan !== '') { $d['pan'] = $pan; }
        db_connect()->table('partners')->where('id', $partnerId)->update(['payout_enc' => TokenCipher::encrypt((string) json_encode($d)), 'updated_at' => $this->stamp()]);
        $this->log($partnerId, 'partner', $partnerId, 'payout_details.changed', $method, $ip);
        return $this->maskedPayout($d);
    }

    /** @return ?array the decrypted details (admin use only) */
    public function payoutDetails(int $partnerId): ?array
    {
        $enc = $this->row($partnerId)['payout_enc'];
        if (! $enc) { return null; }
        try { return json_decode(TokenCipher::decrypt($enc), true) ?: null; } catch (\Throwable) { return null; }
    }

    private function maskedPayout(?array $d): ?array
    {
        if (! $d) { return null; }
        $mask = static fn (string $s) => str_repeat('•', max(0, strlen($s) - 4)) . substr($s, -4);
        return ['method' => $d['method'], 'upi' => isset($d['upi']) ? $mask($d['upi']) : null, 'account_name' => $d['account_name'] ?? null, 'account_no' => isset($d['account_no']) ? $mask($d['account_no']) : null,
                'ifsc' => $d['ifsc'] ?? null, 'pan' => isset($d['pan']) ? $mask($d['pan']) : null];
    }

    // ================================================================================================================================
    // views
    // ================================================================================================================================

    public function referralLinks(string $code): array
    {
        $site = rtrim((string) env('WEBSITE_URL', 'https://tripsarthi.com'), '/');
        return ['code' => $code, 'app_link' => rtrim(EmailService::appUrl(), '/') . '/?ref=' . $code . '#/register', 'site_link' => $site . '/?ref=' . $code];
    }

    /** The one-time set-password link the admin shares with the partner. */
    public static function inviteUrl(string $rawToken): string
    {
        $base = (string) env('PARTNER_URL', '');
        if ($base === '') { $base = str_contains(EmailService::appUrl(), 'localhost') ? 'http://localhost:5919' : 'https://partners.tripsarthi.com'; }
        return rtrim($base, '/') . '/#/set-password?token=' . $rawToken;
    }

    /** Admin list: every partner with the numbers that matter. */
    public function partners(): array
    {
        $db = db_connect(); $now = $this->stamp();
        $rows = $db->table('partners')->orderBy('id', 'DESC')->get()->getResultArray();
        $refs = []; foreach ($db->query('SELECT referred_partner_id pid, COUNT(*) n FROM tenants WHERE referred_partner_id IS NOT NULL AND deleted_at IS NULL GROUP BY referred_partner_id')->getResultArray() as $r) { $refs[(int) $r['pid']] = (int) $r['n']; }
        $paying = []; foreach ($db->query("SELECT t.referred_partner_id pid, COUNT(DISTINCT t.id) n FROM tenants t JOIN subscriptions s ON s.tenant_id = t.id AND s.status = 'active' AND s.amount_paise > 0 AND s.razorpay_sub_id NOT LIKE 'admin-%' AND s.current_period_end > ?
                                            WHERE t.referred_partner_id IS NOT NULL GROUP BY t.referred_partner_id", [$now])->getResultArray() as $r) { $paying[(int) $r['pid']] = (int) $r['n']; }
        return array_map(function (array $p) use ($refs, $paying) {
            $v = $this->view($p); $v['referrals'] = $refs[(int) $p['id']] ?? 0; $v['paying'] = $paying[(int) $p['id']] ?? 0; $v['balances'] = $this->balances((int) $p['id']);
            return $v;
        }, $rows);
    }

    public function detail(int $id): array
    {
        $db = db_connect(); $p = $this->row($id);
        $tenants = $db->query("SELECT t.id, t.name, t.plan, t.status, t.referred_at, t.referral_source,
                                      (SELECT COUNT(*) FROM subscriptions s WHERE s.tenant_id = t.id AND s.status = 'active' AND s.amount_paise > 0 AND s.razorpay_sub_id NOT LIKE 'admin-%' AND s.current_period_end > ?) paying,
                                      (SELECT COALESCE(SUM(c.base_paise), 0) FROM partner_commissions c WHERE c.tenant_id = t.id AND c.partner_id = t.referred_partner_id AND c.status <> 'void') paid_paise,
                                      (SELECT COALESCE(SUM(c.amount_paise), 0) FROM partner_commissions c WHERE c.tenant_id = t.id AND c.partner_id = t.referred_partner_id AND c.status <> 'void') commission_paise
                                 FROM tenants t WHERE t.referred_partner_id = ? ORDER BY t.referred_at DESC", [$this->stamp(), $id])->getResultArray();
        $commissions = $db->query('SELECT c.*, t.name tenant_name FROM partner_commissions c LEFT JOIN tenants t ON t.id = c.tenant_id WHERE c.partner_id = ? ORDER BY c.id DESC LIMIT 300', [$id])->getResultArray();
        $payouts = $db->query('SELECT o.*, u.name created_by_name FROM partner_payouts o LEFT JOIN users u ON u.id = o.created_by WHERE o.partner_id = ? ORDER BY o.id DESC', [$id])->getResultArray();
        $events = $db->table('partner_events')->where('partner_id', $id)->orderBy('id', 'DESC')->limit(40)->get()->getResultArray();
        $codeCoupons = $db->table('coupons')->select('id,code,type,value,active')->where('partner_id', $id)->get()->getResultArray();
        return ['partner' => $this->view($p) + ['notes' => $p['notes']], 'balances' => $this->balances($id), 'referred' => $tenants, 'commissions' => $commissions, 'payouts' => $payouts, 'events' => $events,
                'payout_details' => $this->payoutDetails($id), 'coupons' => $codeCoupons, 'min_payout_paise' => self::MIN_PAYOUT_PAISE];
    }

    /** What a partner sees: their numbers and their referrals - workspace name and plan state only, never a customer's contact details. */
    public function portal(int $partnerId): array
    {
        $db = db_connect(); $p = $this->row($partnerId);
        $referred = $db->query("SELECT t.name, t.referred_at, t.plan,
                                       (SELECT COUNT(*) FROM subscriptions s WHERE s.tenant_id = t.id AND s.status = 'active' AND s.amount_paise > 0 AND s.razorpay_sub_id NOT LIKE 'admin-%' AND s.current_period_end > ?) paying,
                                       (SELECT COUNT(*) FROM partner_commissions c WHERE c.tenant_id = t.id AND c.partner_id = ? AND c.status <> 'void') payments,
                                       (SELECT COALESCE(SUM(c.amount_paise), 0) FROM partner_commissions c WHERE c.tenant_id = t.id AND c.partner_id = ? AND c.status <> 'void') earned_paise
                                  FROM tenants t WHERE t.referred_partner_id = ? ORDER BY t.referred_at DESC", [$this->stamp(), $partnerId, $partnerId, $partnerId])->getResultArray();
        $referred = array_map(fn ($r) => ['workspace' => $r['name'], 'since' => $r['referred_at'], 'state' => (int) $r['paying'] > 0 ? 'paying' : ((int) $r['payments'] > 0 ? 'lapsed' : 'free'), 'plan' => (int) $r['paying'] > 0 ? $r['plan'] : null,
            'payments' => (int) $r['payments'], 'earned_paise' => (int) $r['earned_paise']], $referred);
        $commissions = $db->query("SELECT c.id, c.plan, c.cycle, c.base_paise, c.rate_pct, c.amount_paise, c.status, c.earned_at, c.payable_after, t.name workspace FROM partner_commissions c LEFT JOIN tenants t ON t.id = c.tenant_id
                                    WHERE c.partner_id = ? ORDER BY c.id DESC LIMIT 200", [$partnerId])->getResultArray();
        $now = $this->stamp();
        $commissions = array_map(fn ($c) => ['id' => (int) $c['id'], 'workspace' => $c['workspace'], 'plan' => $c['plan'], 'cycle' => $c['cycle'], 'base_paise' => (int) $c['base_paise'], 'rate_pct' => (float) $c['rate_pct'],
            'amount_paise' => (int) $c['amount_paise'], 'status' => $c['status'] === 'pending' ? ($c['payable_after'] <= $now ? 'available' : 'pending') : $c['status'], 'earned_at' => $c['earned_at'], 'payable_after' => $c['payable_after']], $commissions);
        $payouts = $db->table('partner_payouts')->select('id,amount_paise,method,reference,commissions,paid_at')->where('partner_id', $partnerId)->orderBy('id', 'DESC')->get()->getResultArray();
        return ['partner' => ['name' => $p['name'], 'company' => $p['company'], 'email' => $p['email'], 'commission_pct' => (float) $p['commission_pct'], 'commission_months' => (int) $p['commission_months'], 'hold_days' => (int) $p['hold_days']],
                'links' => $this->referralLinks($p['code']), 'balances' => $this->balances($partnerId), 'referred' => $referred, 'commissions' => $commissions, 'payouts' => $payouts,
                'payout_details' => $this->maskedPayout($this->payoutDetails($partnerId)), 'min_payout_paise' => self::MIN_PAYOUT_PAISE,
                'preferences' => ['phone' => $p['phone'], 'wa_marketing_opt_in' => (bool) $p['wa_marketing_opt_in']]];
    }

    // ================================================================================================================================
    // helpers
    // ================================================================================================================================

    private function row(int $id): array
    {
        return db_connect()->table('partners')->where('id', $id)->get()->getRowArray() ?: throw new \OutOfBoundsException('Partner not found.');
    }

    private function adminRow(int $id): array { return $this->view($this->row($id)); }

    private function view(array $p): array
    {
        return ['id' => (int) $p['id'], 'name' => $p['name'], 'company' => $p['company'], 'email' => $p['email'], 'phone' => $p['phone'], 'code' => $p['code'], 'status' => $p['status'],
            'commission_pct' => (float) $p['commission_pct'], 'commission_months' => (int) $p['commission_months'], 'hold_days' => (int) $p['hold_days'], 'has_password' => $p['password_hash'] !== null,
            'invite_pending' => $p['invite_token_hash'] !== null && $p['invite_expires_at'] > $this->stamp(), 'last_login_at' => $p['last_login_at'], 'created_at' => $p['created_at'], 'links' => $this->referralLinks($p['code'])];
    }

    /** Commission terms from input, falling back to the current values (or the program defaults for a new partner). */
    private function terms(array $in, ?array $cur): array
    {
        $pct = array_key_exists('commission_pct', $in) ? (float) $in['commission_pct'] : (float) ($cur['commission_pct'] ?? self::DEFAULT_PCT);
        $months = array_key_exists('commission_months', $in) ? (int) $in['commission_months'] : (int) ($cur['commission_months'] ?? self::DEFAULT_MONTHS);
        $hold = array_key_exists('hold_days', $in) ? (int) $in['hold_days'] : (int) ($cur['hold_days'] ?? self::DEFAULT_HOLD_DAYS);
        if ($pct < 1 || $pct > 60) { throw new \InvalidArgumentException('Commission must be between 1% and 60%.'); }
        if ($months < 0 || $months > 120) { throw new \InvalidArgumentException('Commission period must be 0 (lifetime) to 120 months.'); }
        if ($hold < 0 || $hold > 180) { throw new \InvalidArgumentException('Hold period must be between 0 and 180 days.'); }
        return ['commission_pct' => round($pct, 2), 'commission_months' => $months, 'hold_days' => $hold];
    }

    /** @return array{0:string,1:string} [raw, sha256] */
    private function newInviteToken(): array { $raw = bin2hex(random_bytes(24)); return [$raw, hash('sha256', $raw)]; }

    private function byInvite(string $raw): array
    {
        if (! preg_match('/^[a-f0-9]{48}$/', $raw)) { throw new \OutOfBoundsException('This link is not valid.'); }
        $p = db_connect()->table('partners')->where('invite_token_hash', hash('sha256', $raw))->get()->getRowArray();
        if (! $p || $p['invite_expires_at'] <= $this->stamp()) { throw new \OutOfBoundsException('This link is not valid or has expired. Ask TripSarthi for a new one.'); }
        return $p;
    }

    private function freshCode(string $name): string
    {
        $db = db_connect();
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'PARTNER', 0, 8));
        for ($i = 0; $i < 20; $i++) {
            $c = $base . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
            if ($db->table('partners')->where('code', $c)->countAllResults() === 0 && $db->table('coupons')->where('code', $c)->countAllResults() === 0) { return $c; }
        }
        throw new \RuntimeException('Could not generate a unique code.');
    }

    public function log(int $partnerId, string $actor, ?int $actorId, string $action, ?string $detail = null, string $ip = ''): void
    {
        try {
            db_connect()->table('partner_events')->insert(['partner_id' => $partnerId, 'actor' => $actor, 'actor_id' => $actorId, 'action' => $action, 'detail' => $detail !== null ? mb_substr($detail, 0, 500) : null,
                'ip' => mb_substr($ip, 0, 45) ?: null, 'created_at' => $this->stamp()]);
        } catch (\Throwable $e) { log_message('error', 'partner event log failed: ' . $e->getMessage()); }
    }
}
