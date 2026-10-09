<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use CodeIgniter\RESTful\ResourceController;

/** Shared helpers for the travel API controllers. */
abstract class TravelBaseController extends ResourceController
{
    protected $format = 'json';

    protected function body(): array
    {
        return $this->request->getJSON(true) ?: [];
    }

    protected function ok($data, int $code = 200)
    {
        return $this->respond(['success' => true, 'data' => $data], $code);
    }

    /** JSON columns arrive as arrays from the SPA; store as JSON text. */
    protected function encodeJson(array $d, array $keys): array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $d) && ! is_string($d[$k]) && $d[$k] !== null) {
                $d[$k] = json_encode($d[$k]);
            }
        }
        return $d;
    }
}
