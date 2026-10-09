<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\BaseModel;
use App\Models\DestinationModel;
use App\Models\SupplierModel;
use App\Models\SupplierRateModel;
use App\Services\Auth\CurrentUser;

/**
 * Master data CRUD for destinations, suppliers and supplier rate cards.
 * One controller, resource chosen by route: /travel/{destinations|suppliers|rates}.
 */
class TravelMasterController extends TravelBaseController
{
    private const MAP = [
        'destinations' => [DestinationModel::class, 'name'],
        'suppliers'    => [SupplierModel::class, 'name'],
        'rates'        => [SupplierRateModel::class, 'service_name'],
    ];

    private function model(string $res): BaseModel
    {
        [$class] = self::MAP[$res] ?? throw new \CodeIgniter\Exceptions\PageNotFoundException('Unknown resource.');
        return (new $class())->setTenant(CurrentUser::tenantId());
    }

    public function listAll(string $res = '')
    {
        $m = $this->model($res);
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $m->like(self::MAP[$res][1], $q);
        }
        foreach (['type', 'supplier_id', 'destination_id', 'service_type'] as $f) {
            if (($v = $this->request->getGet($f)) !== null && $v !== '') {
                $m->where($f, $v);
            }
        }
        return $this->ok($m->orderBy('id', 'DESC')->findAll(500));
    }

    public function one(string $res = '', $id = null)
    {
        $row = $this->model($res)->find((int) $id);
        return $row ? $this->ok($row) : $this->failNotFound('Not found.');
    }

    public function store(string $res = '')
    {
        $m  = $this->model($res);
        $id = $m->insert($this->encodeJson($this->body(), ['meta']), true);
        return $id ? $this->ok($this->model($res)->find((int) $id), 201) : $this->fail($m->errors() ?: 'Invalid.', 422);
    }

    public function save(string $res = '', $id = null)
    {
        $m = $this->model($res);
        if (! $m->find((int) $id)) {
            return $this->failNotFound('Not found.');
        }
        $m->update((int) $id, $this->encodeJson($this->body(), ['meta']));
        return $this->ok($this->model($res)->find((int) $id));
    }

    public function destroy(string $res = '', $id = null)
    {
        $m = $this->model($res);
        if (! $m->find((int) $id)) {
            return $this->failNotFound('Not found.');
        }
        $m->delete((int) $id);
        return $this->ok(['deleted' => true]);
    }
}
