<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Crm\AuditLogger;
use App\Services\Email\EmailService;

/**
 * TripSarthi's OWN WhatsApp marketing runs inside one ordinary workspace (the "marketing workspace"): it has its own WhatsApp number,
 * templates, contacts and Campaigns page, so all WhatsApp rules (approved templates, 24-hour window, STOP, quality gate) apply exactly as
 * for customers. This service says whether that workspace is READY, lets the team choose it, and reports campaign results.
 */
final class PlatformMarketingService
{
    public function __construct(private readonly ?int $now = null) {}

    /** @return array{workspace:?array,checks:list<array>,ready:bool,contacts:array,campaign_url:string,numbers:array} */
    public function status(): array
    {
        $db = db_connect();
        $id = (new PlatformAudienceService($this->now))->marketingTenant();
        $base = rtrim(EmailService::appUrl(), '/');
        if ($id === null || $db->table('tenants')->where('id', $id)->where('deleted_at', null)->countAllResults() === 0) {
            return ['workspace' => null, 'checks' => [['key' => 'workspace', 'ok' => false, 'text' => 'Choose the workspace that runs TripSarthi marketing']], 'ready' => false,
                    'contacts' => ['total' => 0, 'opted_in' => 0, 'platform' => 0], 'campaign_url' => $base . '/#/campaigns', 'numbers' => []];
        }
        $ws = (new WhatsappOversightService($this->now))->workspace($id)['workspace'];
        $approved = (int) $db->table('templates')->where('tenant_id', $id)->where('category', 'marketing')->where('meta_status', 'approved')->where('deleted_at', null)->countAllResults();
        $total = (int) $db->table('contacts')->where('tenant_id', $id)->where('deleted_at', null)->countAllResults();
        $optIn = (int) $db->table('contacts')->where('tenant_id', $id)->where('deleted_at', null)->where('opt_in', 1)->where('wa_number IS NOT NULL', null, false)->countAllResults();
        $tagged = (int) $db->query('SELECT COUNT(DISTINCT c.id) n FROM contacts c JOIN contact_tags ct ON ct.contact_id = c.id JOIN tags t ON t.id = ct.tag_id AND t.name = ? AND t.tenant_id = c.tenant_id WHERE c.tenant_id = ? AND c.deleted_at IS NULL AND c.opt_in = 1', [PlatformAudienceService::TAG_ALL, $id])->getRowArray()['n'];

        $checks = [
            ['key' => 'workspace', 'ok' => true, 'text' => 'Marketing workspace chosen: ' . $ws['name']],
            ['key' => 'whatsapp', 'ok' => $ws['whatsapp'] === 'active', 'text' => $ws['whatsapp'] === 'active' ? 'WhatsApp is connected' : 'WhatsApp is not connected in that workspace (Settings → WhatsApp)'],
            ['key' => 'quality', 'ok' => in_array($ws['quality'], ['green', 'unknown'], true), 'text' => in_array($ws['quality'], ['green', 'unknown'], true) ? 'Number quality is healthy' : ($ws['quality'] === 'none' ? 'No WhatsApp number is connected' : 'Number quality is ' . strtoupper($ws['quality']) . ' - marketing is held back')],
            ['key' => 'templates', 'ok' => $approved > 0, 'text' => $approved > 0 ? "{$approved} approved marketing template(s)" : 'No approved marketing template yet (create one in Templates and submit it to Meta)'],
            ['key' => 'audience', 'ok' => $optIn > 0, 'text' => $optIn > 0 ? "{$optIn} opted-in contact(s)" : 'No opted-in contacts yet - add a segment below'],
            ['key' => 'not_paused', 'ok' => ! $ws['paused'], 'text' => $ws['paused'] ? 'Marketing sends are paused for this workspace' : 'Marketing sends are not paused'],
        ];
        return ['workspace' => ['id' => $ws['id'], 'name' => $ws['name'], 'plan' => $ws['plan'], 'whatsapp' => $ws['whatsapp'], 'quality' => $ws['quality']], 'checks' => $checks, 'ready' => ! in_array(false, array_column($checks, 'ok'), true),
                'contacts' => ['total' => $total, 'opted_in' => $optIn, 'platform' => $tagged], 'campaign_url' => $base . '/#/campaigns', 'numbers' => $ws['numbers']];
    }

    /** Choose (or clear, with null) the marketing workspace. Existing contacts stay where they are. */
    public function setWorkspace(?int $tenantId, int $adminId): array
    {
        $db = db_connect();
        if ($tenantId !== null) {
            $t = $db->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->get()->getRowArray() ?: throw new \OutOfBoundsException('Workspace not found.');
            if ($t['status'] !== 'active') { throw new \DomainException('That workspace is ' . $t['status'] . '. Choose an active one.'); }
        }
        $before = PlatformSettings::get(PlatformSettings::MARKETING_TENANT);
        PlatformSettings::set(PlatformSettings::MARKETING_TENANT, $tenantId === null ? null : (string) $tenantId, $adminId);
        $entity = $tenantId ?? ($before ? (int) $before : null);
        AuditLogger::log('admin.marketing_workspace', 'tenant', $entity, ['tenant_id' => $before], ['tenant_id' => $tenantId], $entity, $adminId);
        return $this->status();
    }

    /** Recent campaigns of the marketing workspace with their delivery numbers. */
    public function campaigns(): array
    {
        $id = (new PlatformAudienceService($this->now))->marketingTenant();
        if ($id === null) { return []; }
        try { return (new WhatsappOversightService($this->now))->workspace($id)['campaigns']; } catch (\OutOfBoundsException) { return []; }
    }
}
