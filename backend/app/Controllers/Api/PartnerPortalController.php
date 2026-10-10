<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Partner\CurrentPartner;
use App\Services\Partner\PartnerService;
use CodeIgniter\RESTful\ResourceController;

/**
 * /api/v1/partner/* - a partner's own dashboard (filter: partnerauth). Everything is scoped to the signed-in partner; there is no id in any URL.
 * Errors: 422 bad input, 409 not allowed (wrong current password). Never 401 for those (the portal signs out on 401).
 */
class PartnerPortalController extends ResourceController
{
    protected $format = 'json';

    private function run(callable $fn)
    {
        try { return $this->respond(['success' => true, 'data' => $fn()]); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 409); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 404); }
    }

    private function body(): array { return (array) ($this->request->getJSON(true) ?? []); }

    public function dashboard() { return $this->run(fn () => (new PartnerService())->portal(CurrentPartner::id())); }

    /** PUT /partner/payout-details { method: upi|bank, upi | account_name,account_no,ifsc, pan?, current_password } */
    public function savePayout()
    {
        $b = $this->body();
        return $this->run(fn () => (new PartnerService())->savePayoutDetails(CurrentPartner::id(), $b, (string) ($b['current_password'] ?? ''), $this->request->getIPAddress()));
    }

    /** PUT /partner/password { current, new } */
    public function changePassword()
    {
        $b = $this->body();
        return $this->run(function () use ($b) { (new PartnerService())->changePassword(CurrentPartner::id(), (string) ($b['current'] ?? ''), (string) ($b['new'] ?? ''), $this->request->getIPAddress()); return ['changed' => true]; });
    }
}
