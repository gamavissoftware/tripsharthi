<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Partner\CurrentPartner;
use App\Services\Partner\PartnerService;
use App\Services\Partner\PartnerSessionService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Sign-in for the partner portal (its own login + sessions, separate from customers and staff).
 *   POST partner-auth/login {email,password} -> {token,partner}     GET partner-auth/invite/:token     POST partner-auth/accept {token,password}
 *   GET partner-auth/me       POST partner-auth/logout
 * A wrong password, an unknown email, an invited-but-not-set-up or a suspended partner all answer the same "Invalid email or password."
 */
class PartnerAuthController extends ResourceController
{
    protected $format = 'json';

    private function brief(array $p): array { return ['id' => (int) $p['id'], 'name' => $p['name'], 'email' => $p['email'], 'code' => $p['code']]; }

    public function login(): ResponseInterface
    {
        $email = strtolower(trim((string) $this->request->getJsonVar('email')));
        $pass  = (string) $this->request->getJsonVar('password');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '' || strlen($pass) > 200) { return $this->fail(['message' => 'Enter your email and password.'], 422); }
        if (! service('throttler')->check('partnerlogin_' . md5($this->request->getIPAddress() . '|' . $email), 5, 900)) { return $this->respond(['success' => false, 'message' => 'Too many attempts. Try again in a few minutes.'], 429); }

        $svc = new PartnerService();
        $p = $svc->verifyLogin($email, $pass, $this->request->getIPAddress());
        if ($p === null) { return $this->respond(['success' => false, 'message' => 'Invalid email or password.'], 401); }
        $token = (new PartnerSessionService())->create((int) $p['id'], $this->request->getIPAddress(), $this->request->getUserAgent()->getAgentString());
        return $this->respond(['success' => true, 'token' => $token, 'partner' => $this->brief($p)]);
    }

    public function invite($token = null): ResponseInterface
    {
        if (! service('throttler')->check('partnerinvite_' . md5($this->request->getIPAddress()), 30, 600)) { return $this->respond(['success' => false, 'message' => 'Too many tries. Please wait a few minutes.'], 429); }
        try { return $this->respond(['success' => true, 'data' => (new PartnerService())->inviteInfo((string) $token)]); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 404); }
    }

    public function accept(): ResponseInterface
    {
        if (! service('throttler')->check('partnerinvite_' . md5($this->request->getIPAddress()), 30, 600)) { return $this->respond(['success' => false, 'message' => 'Too many tries. Please wait a few minutes.'], 429); }
        try {
            $p = (new PartnerService())->acceptInvite((string) $this->request->getJsonVar('token'), (string) $this->request->getJsonVar('password'), $this->request->getIPAddress(), filter_var($this->request->getJsonVar('wa_opt_in'), FILTER_VALIDATE_BOOLEAN));
        } catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 422); }
        if ($p['status'] !== 'active') { return $this->respond(['success' => true, 'token' => null, 'partner' => $this->brief($p)]); }       // suspended partners can set a password but not sign in
        $token = (new PartnerSessionService())->create((int) $p['id'], $this->request->getIPAddress(), $this->request->getUserAgent()->getAgentString());
        return $this->respond(['success' => true, 'token' => $token, 'partner' => $this->brief($p)]);
    }

    public function me(): ResponseInterface { return $this->respond(['success' => true, 'partner' => $this->brief((array) CurrentPartner::get())]); }

    public function logout(): ResponseInterface
    {
        $h = $this->request->getHeaderLine('Authorization');
        if (str_starts_with($h, 'Bearer ')) { (new PartnerSessionService())->revoke(substr($h, 7)); }
        return $this->respond(['success' => true]);
    }
}
