<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Admin\MobileAppLinks;
use CodeIgniter\RESTful\ResourceController;

/** GET /api/v1/public/mobile-app: { available, android, ios }. Public, read-only, no personal data; the login screen and the website draw buttons from it. */
class MobileAppController extends ResourceController
{
    protected $format = 'json';

    public function links()
    {
        return $this->respond(['success' => true, 'data' => MobileAppLinks::publicView()]);
    }
}
