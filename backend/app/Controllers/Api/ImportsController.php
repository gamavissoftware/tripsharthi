<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\LeadImportModel;
use App\Services\Auth\CurrentUser;
use App\Services\Flow\JobDispatcher;
use App\Services\Leads\Importer;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

class ImportsController extends ResourceController
{
    protected $format = 'json';

    private function importer(): Importer
    {
        return new Importer(
            new LeadImportModel(),
            new ContactModel(),
            new ContactFieldValueModel()
        );
    }

    // GET /api/v1/imports
    public function index(): ResponseInterface
    {
        $model   = (new LeadImportModel())->setTenant(CurrentUser::tenantId());
        $imports = $model->orderBy('created_at', 'DESC')->findAll(20);
        return $this->respond(['success' => true, 'data' => $imports]);
    }

    // GET /api/v1/imports/:id
    public function show($id = null): ResponseInterface
    {
        $import = (new LeadImportModel())->setTenant(CurrentUser::tenantId())->find((int) $id);
        if (! $import) {
            return $this->failNotFound("Import #{$id} not found.");
        }
        // Decode JSON columns for readability
        $import['headers'] = json_decode($import['headers'] ?? '[]', true);
        $import['errors']  = json_decode($import['errors']  ?? '[]', true);
        return $this->respond(['success' => true, 'data' => $import]);
    }

    // POST /api/v1/imports  (multipart/form-data: file + optional default_country_code)
    public function upload(): ResponseInterface
    {
        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->fail(['file' => 'A valid CSV or Excel file is required.'], 422);
        }

        $allowed = ['csv', 'xlsx', 'xls'];
        if (! in_array(strtolower($file->getClientExtension()), $allowed, true)) {
            return $this->fail(['file' => 'Only CSV, XLSX, and XLS files are accepted.'], 422);
        }

        $countryCode = (string) ($this->request->getPost('default_country_code') ?? '');

        try {
            $result = $this->importer()->storeAndPreview($file, CurrentUser::tenantId(), $countryCode);
            return $this->respondCreated(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            log_message('error', 'Import upload failed: ' . $e->getMessage());
            return $this->failServerError('Failed to process the uploaded file.');
        }
    }

    // POST /api/v1/imports/:id/map
    public function map($id = null): ResponseInterface
    {
        // assoc=true: a JSON *object* decodes to stdClass otherwise, so the
        // is_array() guard below rejected every mapping the UI sent.
        $mapping = $this->request->getJsonVar('mapping', true);
        if (! is_array($mapping) || empty($mapping)) {
            return $this->fail(['mapping' => 'A non-empty mapping object is required.'], 422);
        }

        $result = $this->importer()->saveMapping((int) $id, CurrentUser::tenantId(), $mapping);
        if (! $result['ok']) {
            return $this->fail(['mapping' => implode(' ', $result['errors'])], 422);
        }

        return $this->respond(['success' => true, 'message' => 'Mapping saved.']);
    }

    // POST /api/v1/imports/:id/start
    // Phase 5: dispatches a lead_import job instead of calling processBatch() directly.
    // Poll GET /api/v1/imports/{id} for progress (imported, failed, total, status).
    public function start($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $import   = (new LeadImportModel())->setTenant($tenantId)->find((int) $id);

        if (! $import) return $this->failNotFound("Import #{$id} not found.");

        if (! in_array($import['status'], ['mapped', 'processing'], true)) {
            return $this->fail("Import cannot be started in status '{$import['status']}'.", 422);
        }

        if (empty($import['mapping'])) {
            return $this->fail('Import mapping not saved. Call POST /imports/{id}/map first.', 422);
        }

        JobDispatcher::dispatch(
            tenantId:   $tenantId,
            type:       'lead_import',
            payload:    ['import_id' => (int) $id],
            sourceType: 'lead_imports',
            sourceId:   (int) $id,
        );

        return $this->respond([
            'success' => true,
            'status'  => 'queued',
            'message' => 'Import queued. Poll GET /api/v1/imports/{id} for progress.',
        ]);
    }

    // POST /api/v1/imports/:id/continue
    // Phase 5: no longer drives batches — the worker does that now.
    // Returns the current import state for backward compatibility.
    // Clients should poll GET /api/v1/imports/{id} instead.
    public function continue($id = null): ResponseInterface
    {
        $import = (new LeadImportModel())->setTenant(CurrentUser::tenantId())->find((int) $id);
        if (! $import) return $this->failNotFound("Import #{$id} not found.");

        return $this->respond([
            'success' => true,
            'data'    => $import,
            'message' => 'Batch processing is worker-driven. Poll GET /api/v1/imports/{id} for progress.',
        ]);
    }
}
