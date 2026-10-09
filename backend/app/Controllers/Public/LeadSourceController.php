<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Leads\PortalLeadService;
use CodeIgniter\RESTful\ResourceController;

/**
 * POST /api/v1/public/lead-sources/{token} — the webhook a travel portal / partner / Zapier posts enquiries to. JSON or form body.
 * The token is the credential. Never answers 401 (the SPA would log staff out). A rejected lead is 422 so the sender can see why.
 */
class LeadSourceController extends ResourceController
{
    protected $format = 'json';
    private const MAX_BODY = 102_400;

    public function receive($token = '')
    {
        $key = 'lead_src_' . md5($this->request->getIPAddress() . '|' . (string) $token);
        if (! service('throttler')->check($key, 120, MINUTE)) { return $this->respond(['success' => false, 'message' => 'Too many requests.'], 429); }
        $raw = (string) $this->request->getBody();
        if (strlen($raw) > self::MAX_BODY) { return $this->respond(['success' => false, 'message' => 'Payload too large.'], 413); }
        $payload = str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'json') ? json_decode($raw, true) : $this->request->getPost();
        if (! is_array($payload) || $payload === []) { return $this->respond(['success' => false, 'message' => 'Send the lead as a JSON object or a form.'], 422); }
        try {
            $r = (new PortalLeadService())->ingestWebhook((string) $token, $payload);
        } catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => 'Unknown lead source.'], 404); }
        catch (\Throwable $e) { return $this->respond(['success' => false, 'message' => 'Could not save the lead.'], 500); }
        return $this->respond(['success' => $r['status'] !== 'rejected'] + $r, $r['status'] === 'rejected' ? 422 : 200);
    }
}
