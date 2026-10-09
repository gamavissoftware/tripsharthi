<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Travel\DepartureService;

/** Group / fixed departures: inventory, seat holds, manifest. Creating and editing departures is owner/admin; agents reserve seats. */
class DeparturesController extends TravelBaseController
{
    private function guard(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    private function svc(): DepartureService { return new DepartureService(); }

    /** Agents never see internal cost. */
    private function redact(array $d): array
    {
        if (! in_array(CurrentUser::role(), ['owner', 'admin'], true)) { unset($d['cost_pax'], $d['single_supplement_cost']); }
        return $d;
    }

    public function index()
    {
        $scope = in_array($this->request->getGet('scope'), ['past', 'all'], true) ? (string) $this->request->getGet('scope') : 'upcoming';
        return $this->guard(fn () => array_map(fn ($d) => $this->redact($d), $this->svc()->list(CurrentUser::tenantId(), $scope)));
    }

    public function show($id = null) { return $this->guard(fn () => $this->redact($this->svc()->show(CurrentUser::tenantId(), (int) $id))); }

    public function create() { return $this->guard(fn () => $this->svc()->save(CurrentUser::tenantId(), $this->body(), null, CurrentUser::id()), 201); }

    public function update($id = null) { return $this->guard(fn () => $this->svc()->save(CurrentUser::tenantId(), $this->body(), (int) $id, CurrentUser::id())); }

    public function status($id = null) { return $this->guard(fn () => $this->svc()->setStatus(CurrentUser::tenantId(), (int) $id, (string) ($this->body()['status'] ?? ''), CurrentUser::id())); }

    public function reserve($id = null)
    {
        $b = $this->body();
        return $this->guard(fn () => $this->svc()->reserve(CurrentUser::tenantId(), (int) $id, (int) ($b['trip_id'] ?? 0), (int) ($b['adults'] ?? 0), (int) ($b['children'] ?? 0), (int) ($b['single_rooms'] ?? 0),
            (int) ($b['hold_hours'] ?? DepartureService::DEFAULT_HOLD_HOURS), CurrentUser::id()), 201);
    }

    public function release($holdId = null)
    {
        return $this->guard(function () use ($holdId) { $this->svc()->release(CurrentUser::tenantId(), (int) $holdId, 'released by agent', CurrentUser::id()); return ['released' => true]; });
    }

    public function manifest($id = null) { return $this->guard(fn () => $this->svc()->manifest(CurrentUser::tenantId(), (int) $id)); }

    public function manifestCsv($id = null)
    {
        try { $csv = $this->svc()->manifestCsv(CurrentUser::tenantId(), (int) $id); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 404); }
        return $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8')->setHeader('Content-Disposition', 'attachment; filename="Manifest_departure_' . (int) $id . '.csv"')
            ->setHeader('Cache-Control', 'private, no-store')->setHeader('X-Content-Type-Options', 'nosniff')->setBody($csv);
    }
}
