<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Models\UserModel;
use App\Services\Admin\AppErrorService;
use CodeIgniter\RESTful\ResourceController;

/** POST /api/v1/public/app-error: the phone app reports a crash. Public (a crash can happen before sign-in), tiny, throttled, never answers with data. */
class AppErrorController extends ResourceController
{
    protected $format = 'json';

    public function report()
    {
        $ip = $this->request->getIPAddress();
        if (! service('throttler')->check('apperr_' . md5($ip), 20, 600)) { return $this->respond(['success' => true]); }     // quietly ignore a flood
        $raw = (string) $this->request->getBody();
        if (strlen($raw) > 12000) { return $this->respond(['success' => true]); }
        $in = json_decode($raw, true);
        if (! is_array($in)) { return $this->respond(['success' => true]); }
        // who it was, when the phone is signed in (helps support; optional)
        $uid = null; $tid = null;
        $h = $this->request->getHeaderLine('Authorization');
        if (str_starts_with($h, 'Bearer ') && strlen($h) > 20) {
            $u = (new UserModel())->findByToken(substr($h, 7));
            if ($u !== null) { $uid = (int) (is_array($u) ? $u['id'] : $u->id); $tid = (int) (is_array($u) ? $u['tenant_id'] : $u->tenant_id); }
        }
        try { AppErrorService::record($in, $uid, $tid, $ip); } catch (\Throwable $e) { log_message('error', 'app error report failed: ' . $e->getMessage()); }
        return $this->respond(['success' => true]);
    }
}
