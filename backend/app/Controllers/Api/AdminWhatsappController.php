<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Admin\PlatformAudienceService;
use App\Services\Admin\PlatformMarketingService;
use App\Services\Admin\WhatsappOversightService;
use App\Services\Auth\CurrentUser;

/**
 * /api/v1/admin/whatsapp/* - the TripSarthi team only (filter: adminauth).
 *   overview + workspaces/:id + marketing-pause     = oversight of every workspace's WhatsApp
 *   marketing, marketing/segments/:key[/sync]       = TripSarthi's own WhatsApp marketing (consent-based audiences)
 * Errors: 404 not found, 422 bad input, 409 not allowed now. Never 401.
 */
class AdminWhatsappController extends TravelBaseController
{
    private function run(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    private function g(string $k, string $d = ''): string { return (string) ($this->request->getGet($k) ?? $d); }

    public function overview()            { return $this->run(fn () => (new WhatsappOversightService())->overview($this->g('q'), $this->g('filter'))); }
    public function workspace($id = null) { return $this->run(fn () => (new WhatsappOversightService())->workspace((int) $id)); }

    /** POST /admin/whatsapp/workspaces/:id/marketing-pause { paused: bool, reason? } */
    public function pause($id = null)
    {
        $b = $this->body();
        return $this->run(fn () => (new WhatsappOversightService())->setMarketingPaused((int) $id, ! empty($b['paused']), (string) ($b['reason'] ?? ''), CurrentUser::id()));
    }

    /** GET /admin/whatsapp/marketing -> readiness + segments + campaigns */
    public function marketing()
    {
        return $this->run(fn () => ['status' => (new PlatformMarketingService())->status(), 'segments' => (new PlatformAudienceService())->segments(), 'campaigns' => (new PlatformMarketingService())->campaigns()]);
    }

    /** PUT /admin/whatsapp/marketing { tenant_id: int|null } */
    public function setMarketing()
    {
        $b = $this->body();
        return $this->run(fn () => (new PlatformMarketingService())->setWorkspace(isset($b['tenant_id']) && $b['tenant_id'] !== '' ? (int) $b['tenant_id'] : null, CurrentUser::id()));
    }

    public function segment($key = null)  { return $this->run(fn () => (new PlatformAudienceService())->preview((string) $key)); }
    public function sync($key = null)     { return $this->run(fn () => (new PlatformAudienceService())->sync((string) $key, CurrentUser::id())); }
}
