<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Admin\PlatformAdminService;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\AuditLogger;
use App\Services\Marketing\ChatService;

/**
 * /api/v1/admin/* — the TripSarthi team only (filters: auth + platformadmin). Customers, subscriptions, website enquiries and live chat.
 * Errors: 404 not found, 422 bad input, 409 not allowed now. Never 401 (the SPA logs staff out on 401).
 */
class PlatformAdminController extends TravelBaseController
{
    private function svc(): PlatformAdminService { return new PlatformAdminService(); }

    private function run(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    private function g(string $k, string $d = ''): string { return (string) ($this->request->getGet($k) ?? $d); }

    public function overview() { return $this->run(fn () => $this->svc()->overview()); }

    public function tenants() { return $this->run(fn () => $this->svc()->tenants($this->g('q'), $this->g('plan'), $this->g('status'), (int) $this->g('page', '1'), (int) $this->g('per_page', '25'))); }

    public function tenant($id = null) { return $this->run(fn () => $this->svc()->tenant((int) $id)); }

    /** PUT /admin/tenants/:id/plan { plan, days?, amount_rs?, note?, force? } */
    public function setPlan($id = null)
    {
        $b = $this->body();
        return $this->run(fn () => $this->svc()->setPlan((int) $id, (string) ($b['plan'] ?? ''), (int) ($b['days'] ?? 30), (float) ($b['amount_rs'] ?? 0), CurrentUser::id(), ! empty($b['force']), (string) ($b['note'] ?? '')));
    }

    /** POST /admin/tenants/:id/status { status: active|suspended|cancelled, reason? } */
    public function setStatus($id = null)
    {
        $b = $this->body();
        return $this->run(fn () => $this->svc()->setStatus((int) $id, (string) ($b['status'] ?? ''), CurrentUser::id(), CurrentUser::tenantId(), (string) ($b['reason'] ?? '')));
    }

    public function subscriptions() { return $this->run(fn () => $this->svc()->subscriptions($this->g('status'), $this->g('plan'), (int) $this->g('page', '1'), (int) $this->g('per_page', '25'))); }

    // ---- website enquiries ------------------------------------------------------------------------------------------------------------------

    public function enquiries()
    {
        return $this->run(function () {
            $q = db_connect()->table('contact_enquiries')->orderBy('id', 'DESC')->limit(200);
            $st = $this->g('status'); if (in_array($st, ['new', 'handled', 'spam'], true)) { $q->where('status', $st); }
            return $q->select('id,name,email,phone,company,topic,message,status,source,notified_at,created_at')->get()->getResultArray();
        });
    }

    /** PUT /admin/enquiries/:id { status: new|handled|spam } */
    public function enquiryStatus($id = null)
    {
        $b = $this->body();
        return $this->run(function () use ($id, $b) {
            $st = (string) ($b['status'] ?? ''); if (! in_array($st, ['new', 'handled', 'spam'], true)) { throw new \InvalidArgumentException('Unknown status.'); }
            $db = db_connect(); if (! $db->table('contact_enquiries')->where('id', (int) $id)->countAllResults()) { throw new \OutOfBoundsException('Enquiry not found.'); }
            $db->table('contact_enquiries')->where('id', (int) $id)->update(['status' => $st]);
            AuditLogger::log('admin.enquiry.status', 'contact_enquiry', (int) $id, null, ['status' => $st], CurrentUser::tenantId(), CurrentUser::id());
            return ['id' => (int) $id, 'status' => $st];
        });
    }

    // ---- live chat ------------------------------------------------------------------------------------------------------------------------------

    public function chats() { return $this->run(fn () => ['sessions' => (new ChatService())->sessions($this->g('status', 'open') === 'closed' ? 'closed' : 'open'), 'team_online' => true]); }

    public function chat($id = null) { return $this->run(fn () => (new ChatService())->thread((int) $id, (int) $this->g('after', '0'))); }

    public function chatReply($id = null)
    {
        $b = $this->body();
        return $this->run(fn () => (new ChatService())->agentReply((int) $id, (string) ($b['body'] ?? ''), CurrentUser::id()), 201);
    }

    public function chatClose($id = null) { return $this->run(function () use ($id) { (new ChatService())->close((int) $id); return ['closed' => true]; }); }
}
