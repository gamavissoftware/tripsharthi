<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Partner\CurrentPartner;
use App\Services\Partner\PartnerSessionService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Guards /api/v1/partner/*: only a live PARTNER session is accepted (not a customer or admin token). 401 without one. */
class PartnerAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ResponseInterface|null
    {
        $h = $request->getHeaderLine('Authorization');
        $p = str_starts_with($h, 'Bearer ') ? (new PartnerSessionService())->partner(substr($h, 7)) : null;
        if ($p === null) {
            return service('response')->setStatusCode(401)->setContentType('application/json')->setBody(json_encode(['success' => false, 'message' => 'Session expired. Please sign in again.']));
        }
        CurrentPartner::set($p);
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface|null { return null; }
}
