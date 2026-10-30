<?php

declare(strict_types=1);

namespace App\Services\Admin;

/**
 * Lets a signed-in PLATFORM ADMIN work inside TripSarthi's OWN marketing workspace (Admin -> WhatsApp -> Inbox / Campaigns / Templates) with the admin
 * session, acting as that workspace's owner. This is NOT "log in as customer": the workspace is TripSarthi's own (chosen on Admin -> WhatsApp -> Our
 * marketing), so no customer data is reachable unless the team deliberately chose a customer workspace as the marketing workspace.
 *
 * Opt-in per request (header `X-TS-Marketing-Workspace: 1`, sent only by the admin app for the WhatsApp module's own pages) and limited to an allow-list
 * of API areas; anything else answers 403 so an admin token can never be used on the rest of the customer API. Changes made this way are tagged
 * `impersonated_by` in the workspace audit log (shown as "TripSarthi support").
 */
final class MarketingWorkspaceAccess
{
    public const HEADER = 'X-TS-Marketing-Workspace';

    /** API areas (first path segment after /api/v1/) the WhatsApp and CRM modules need. */
    private const AREAS = ['inbox', 'templates', 'campaigns', 'contacts', 'segments', 'tags', 'ai', 'payments', 'custom-fields', 'users', 'whatsapp', 'waba', 'flows', 'quick-replies', 'conversations', 'notifications',
        // the admin CRM module (Subscriber Funnel): leads, pipeline, tasks, meetings, notes, Facebook lead-ad connection, e-mail marketing, saved views
        'pipelines', 'deals', 'tasks', 'meetings', 'notes', 'activities', 'accounts', 'meta-integrations', 'meta-leads', 'email-marketing', 'crm', 'web-forms', 'lead-sources', 'imports'];

    /** Inside an allowed area, these stay closed (the workspace audit log is the customer-facing record of support access itself). */
    private const DENY = ['crm/audit-logs'];

    public static function allowed(string $path): bool
    {
        $p = trim((string) preg_replace('#^(?:.*?/)?api/v1/#', '', trim($path, '/') . '/'), '/');
        foreach (self::DENY as $d) { if ($p === $d || str_starts_with($p, $d . '/')) { return false; } }
        $first = explode('/', $p, 2)[0];
        return in_array($first, self::AREAS, true);
    }

    /**
     * @return array{user:array,admin:array}|string a row pair, or a short code: 'token' (not an admin session), 'none' (no marketing workspace chosen / no owner)
     */
    public static function resolve(string $rawToken): array|string
    {
        $admin = (new AdminSessionService())->user($rawToken);
        if ($admin === null) { return 'token'; }
        $tid = (new PlatformAudienceService())->marketingTenant();
        if ($tid === null) { return 'none'; }
        $db = db_connect();
        $t = $db->table('tenants')->select('id')->where('id', $tid)->where('deleted_at', null)->get()->getRowArray();
        if (! $t) { return 'none'; }
        $owner = $db->table('users')->where('tenant_id', $tid)->where('role', 'owner')->where('deleted_at', null)->orderBy('id')->get()->getRowArray();
        return $owner ? ['user' => $owner, 'admin' => $admin] : 'none';
    }

    /** The CurrentUser impersonation marker for such a request (audit tagging only). */
    public static function marker(array $admin): array
    {
        return ['id' => 0, 'admin_user_id' => (int) $admin['id'], 'read_only' => 0, 'kind' => 'marketing'];
    }
}
