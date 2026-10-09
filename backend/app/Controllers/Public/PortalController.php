<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Travel\PortalService;
use CodeIgniter\RESTful\ResourceController;

/** Customer portal API. No login — the 32-hex token in the link is the credential. Throttled per IP+token; failures are 4xx but NEVER 401 (the SPA logs staff out on 401). */
class PortalController extends ResourceController
{
    protected $format = 'json';

    private function guard(callable $fn, string $bucket, int $perMinute)
    {
        $key = 'portal_' . $bucket . '_' . md5($this->request->getIPAddress() . '|' . (string) $this->request->getUri()->getSegment(5));
        if (! service('throttler')->check($key, $perMinute, MINUTE)) {
            return $this->respond(['success' => false, 'message' => 'Too many requests. Please wait a minute and try again.'], 429);
        }
        try { return $this->respond(['success' => true, 'data' => $fn()]); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 409); }
        catch (\Throwable $e) { log_message('error', 'portal: ' . $e->getMessage()); return $this->respond(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500); }
    }

    public function show($token = '')
    {
        return $this->guard(fn () => (new PortalService())->view((string) $token), 'view', 60);
    }

    public function payLink($token = '', $paymentId = null)
    {
        return $this->guard(fn () => (new PortalService())->payLink((string) $token, (int) $paymentId), 'pay', 10);
    }

    public function upload($token = '', $itemId = null)
    {
        return $this->guard(function () use ($token, $itemId) {
            $f = $this->request->getFile('file');
            if (! $f || ! $f->isValid()) { throw new \InvalidArgumentException('Choose a file to upload.'); }
            return (new PortalService())->upload((string) $token, (int) $itemId, ['tmp_name' => $f->getTempName(), 'name' => $f->getClientName(), 'size' => $f->getSize()]);
        }, 'upload', 10);
    }
}
