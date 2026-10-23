<?php

declare(strict_types=1);

namespace App\Services\Admin;

/**
 * Deletes a customer workspace (or one team member) on request, as described in docs/ACCOUNT_DELETION_RUNBOOK.md and on the website's
 * delete-account page. Everything with a tenant_id goes EXCEPT what TripSarthi must keep as its own business records (subscriptions, the GST
 * invoices and credit notes it issued, referral commissions, support-access log); the workspace row stays as an anonymised stub so those
 * records keep a parent. Files on disk go too. One database transaction; files are removed only after it commits.
 * Exceptions: InvalidArgumentException = refused with a reason.
 */
final class AccountDeletionService
{
    /** TripSarthi's own records about the customer: never deleted here (tax law, accounting, accountability). */
    public const KEEP = ['subscriptions', 'platform_invoices', 'platform_credit_notes', 'coupon_redemptions', 'offer_grants', 'partner_commissions', 'subscription_reminders', 'impersonation_sessions', 'tenants'];
    /** The customer's OWN tax records: deleted unless the owner asks to keep them (--keep-tax-docs). */
    public const TAX_DOCS = ['invoices', 'doc_sequences', 'einvoice_settings', 'gst_filings', 'gst_itc_entries', 'tcs_challans', 'business_profiles'];
    /** Per-workspace folders under writable/uploads. */
    public const FOLDERS = ['invoices', 'portal', 'ad-assets', 'imports', 'social'];

    public function __construct(private readonly ?string $uploadsRoot = null) {}

    private function uploads(): string { return rtrim($this->uploadsRoot ?? (WRITEPATH . 'uploads'), '/'); }

    /** @return list<string> every table that has a tenant_id column (discovered, so a new table can never be forgotten) */
    public function tenantTables(): array
    {
        $rows = db_connect()->query("SELECT DISTINCT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'tenant_id' ORDER BY 1")->getResultArray();
        return array_values(array_map(static fn ($r) => (string) $r['t'], $rows));
    }

    /** @return array{tenant:array,tables:array<string,int>,kept:array<string,int>,users:int,folders:list<string>} what a deletion would remove and keep */
    public function plan(int $tenantId, bool $keepTaxDocs = false): array
    {
        $db = db_connect();
        $t = $db->table('tenants')->where('id', $tenantId)->get()->getRowArray();
        if (! $t) { throw new \InvalidArgumentException("Workspace #{$tenantId} does not exist."); }
        $skip = array_merge(self::KEEP, $keepTaxDocs ? self::TAX_DOCS : []);
        $del = []; $kept = [];
        foreach ($this->tenantTables() as $tbl) {
            $n = (int) $db->table($tbl)->where('tenant_id', $tenantId)->countAllResults();
            if ($n === 0) { continue; }
            if (in_array($tbl, $skip, true)) { $kept[$tbl] = $n; } else { $del[$tbl] = $n; }
        }
        $folders = [];
        foreach (self::FOLDERS as $f) { $p = $this->uploads() . '/' . $f . '/' . $tenantId; if (is_dir($p)) { $folders[] = $f . '/' . $tenantId; } }
        return ['tenant' => $t, 'tables' => $del, 'kept' => $kept, 'users' => (int) $db->table('users')->where('tenant_id', $tenantId)->countAllResults(), 'folders' => $folders];
    }

    private function refuse(array $tenant, int $tenantId): void
    {
        $db = db_connect();
        if (str_starts_with((string) $tenant['slug'], 'deleted-')) { throw new \InvalidArgumentException('That workspace was already deleted.'); }
        if ($db->table('users')->where('tenant_id', $tenantId)->where('is_platform_admin', 1)->countAllResults() > 0) { throw new \InvalidArgumentException('A TripSarthi platform admin belongs to this workspace. Revoke admin rights first (php spark admin:grant <email> --revoke), or do not delete it.'); }
        if ((string) PlatformSettings::get('marketing_tenant_id') === (string) $tenantId) { throw new \InvalidArgumentException('This is the workspace TripSarthi uses for its own marketing. Choose another one in the admin app first.'); }
    }

    /**
     * @return array<string,mixed> summary (rows deleted per table, folders removed)
     * @throws \InvalidArgumentException when refused (wrong confirmation, protected workspace, already deleted)
     */
    public function deleteWorkspace(int $tenantId, string $confirmSlug, string $requestRef, string $executedBy, bool $keepTaxDocs = false): array
    {
        $plan = $this->plan($tenantId, $keepTaxDocs);
        $tenant = $plan['tenant'];
        $this->refuse($tenant, $tenantId);
        if (trim($confirmSlug) !== (string) $tenant['slug']) { throw new \InvalidArgumentException('Confirmation does not match: type the workspace slug exactly (' . $tenant['slug'] . ').'); }
        if (trim($requestRef) === '') { throw new \InvalidArgumentException('Say where the request came from (ticket number, e-mail date...).'); }

        $db = db_connect();
        $skip = array_merge(self::KEEP, $keepTaxDocs ? self::TAX_DOCS : []);
        $deleted = [];
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $db->transStart();
        // children that have no tenant_id of their own
        $db->query('DELETE cf FROM contact_field_values cf JOIN contacts c ON c.id = cf.contact_id WHERE c.tenant_id = ?', [$tenantId]);
        $db->query('DELETE ct FROM contact_tags ct JOIN contacts c ON c.id = ct.contact_id WHERE c.tenant_id = ?', [$tenantId]);
        $db->query('DELETE sm FROM support_messages sm JOIN support_tickets st ON st.id = sm.ticket_id WHERE st.tenant_id = ?', [$tenantId]);
        $db->query('DELETE ut FROM user_tokens ut JOIN users u ON u.id = ut.user_id WHERE u.tenant_id = ?', [$tenantId]);
        $db->query('DELETE ik FROM idempotency_keys ik JOIN users u ON u.id = ik.user_id WHERE u.tenant_id = ?', [$tenantId]);
        foreach ($this->tenantTables() as $tbl) {
            if (in_array($tbl, $skip, true) || $tbl === 'users') { continue; }
            $db->table($tbl)->where('tenant_id', $tenantId)->delete();
            if ($db->affectedRows() > 0) { $deleted[$tbl] = $db->affectedRows(); }
        }
        $db->table('users')->where('tenant_id', $tenantId)->delete(); $deleted['users'] = $db->affectedRows();
        // the workspace row stays as an anonymous stub: subscriptions, invoices and commissions need a parent
        $db->table('tenants')->where('id', $tenantId)->update(['name' => 'Deleted workspace #' . $tenantId, 'slug' => 'deleted-' . $tenantId, 'status' => 'cancelled', 'settings' => null, 'deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $summary = ['rows' => $deleted, 'kept' => $plan['kept'], 'folders' => []];
        $db->table('account_deletions')->insert(['scope' => 'workspace', 'tenant_id' => $tenantId, 'user_id' => null, 'request_ref' => mb_substr($requestRef, 0, 120), 'executed_by' => mb_substr($executedBy, 0, 120), 'kept_tax_docs' => $keepTaxDocs ? 1 : 0, 'summary' => json_encode($summary), 'executed_at' => date('Y-m-d H:i:s')]);
        $db->transComplete();
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        if (! $db->transStatus()) { throw new \RuntimeException('The deletion failed and nothing was removed. See the log.'); }

        foreach (self::FOLDERS as $f) {
            if ($keepTaxDocs && $f === 'invoices') { continue; }
            $p = $this->uploads() . '/' . $f . '/' . $tenantId;
            if (is_dir($p)) { self::rmTree($p); $summary['folders'][] = $f . '/' . $tenantId; }
        }
        foreach (glob($this->uploads() . '/quotes/' . $tenantId . '_*') ?: [] as $q) { @unlink($q); }
        return $summary;
    }

    /** One team member asks to delete their own login. The owner must use deleteWorkspace. Records they created keep only an id. */
    public function deleteUser(int $userId, string $confirmEmail, string $requestRef, string $executedBy): array
    {
        $db = db_connect();
        $u = $db->table('users')->where('id', $userId)->get()->getRowArray();
        if (! $u) { throw new \InvalidArgumentException("User #{$userId} does not exist."); }
        if ((int) $u['is_platform_admin'] === 1) { throw new \InvalidArgumentException('A platform admin cannot be deleted here: revoke admin rights first.'); }
        if ($u['role'] === 'owner') { throw new \InvalidArgumentException('This person is the workspace owner. Deleting the owner means deleting the whole workspace: use deleteWorkspace.'); }
        if (strtolower(trim($confirmEmail)) !== strtolower((string) $u['email'])) { throw new \InvalidArgumentException('Confirmation does not match: type the user\'s e-mail exactly.'); }
        if (trim($requestRef) === '') { throw new \InvalidArgumentException('Say where the request came from.'); }
        $db->transStart();
        $n = [];
        foreach (['user_tokens' => 'user_id', 'idempotency_keys' => 'user_id', 'mobile_devices' => 'user_id', 'push_preferences' => 'user_id', 'push_subscriptions' => 'user_id', 'notifications' => 'user_id', 'push_log' => 'user_id'] as $tbl => $col) {
            if (! $db->fieldExists($col, $tbl)) { continue; }
            $db->table($tbl)->where($col, $userId)->delete(); if ($db->affectedRows() > 0) { $n[$tbl] = $db->affectedRows(); }
        }
        $db->table('users')->where('id', $userId)->update(['name' => 'Former team member', 'email' => 'deleted-' . $userId . '@deleted.invalid', 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'api_token' => null, 'api_token_expires_at' => null,
            'reset_token' => null, 'reset_token_expires_at' => null, 'phone' => null, 'wa_marketing_opt_in' => 0, 'email_marketing_opt_in' => 0, 'deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $db->table('account_deletions')->insert(['scope' => 'user', 'tenant_id' => (int) $u['tenant_id'], 'user_id' => $userId, 'request_ref' => mb_substr($requestRef, 0, 120), 'executed_by' => mb_substr($executedBy, 0, 120), 'kept_tax_docs' => 0, 'summary' => json_encode(['rows' => $n]), 'executed_at' => date('Y-m-d H:i:s')]);
        $db->transComplete();
        if (! $db->transStatus()) { throw new \RuntimeException('The deletion failed and nothing was removed. See the log.'); }
        return ['rows' => $n];
    }

    private static function rmTree(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
}
