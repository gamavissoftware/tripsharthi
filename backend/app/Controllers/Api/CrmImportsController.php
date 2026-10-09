<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Crm\CrmImporter;
use App\Services\Crm\ImportAdapters;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Generic CSV import for accounts / deals / custom-object records (Phase G4).
 * Contacts keep their own importer (ImportsController) untouched.
 *
 * POST /crm/imports                multipart: file, entity, custom_object_id
 * POST /crm/imports/:id/mapping    { mapping: { header: field_key } }
 * POST /crm/imports/:id/process    run the import
 */
class CrmImportsController extends ResourceController
{
    protected $format = 'json';

    public function create(): ResponseInterface
    {
        $entity = (string) $this->request->getPost('entity');
        if (! ImportAdapters::supports($entity)) {
            return $this->fail(['entity' => "Import is not supported for '{$entity}'."], 422);
        }
        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid()) {
            return $this->fail(['file' => 'A valid CSV file is required.'], 422);
        }
        $objId = (int) $this->request->getPost('custom_object_id') ?: null;

        try {
            $res = (new CrmImporter())->storeAndPreview($file, CurrentUser::tenantId(), $entity, $objId);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->respondCreated(['success' => true, 'data' => $res]);
    }

    public function mapping($id = null): ResponseInterface
    {
        $mapping = $this->request->getJsonVar('mapping', true) ?? [];
        if (! is_array($mapping) || empty($mapping)) {
            return $this->fail(['mapping' => 'A column mapping is required.'], 422);
        }
        try {
            $res = (new CrmImporter())->saveMapping((int) $id, CurrentUser::tenantId(), $mapping);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        if (! $res['ok']) {
            return $this->fail(['mapping' => implode(' ', $res['errors'])], 422);
        }
        return $this->respond(['success' => true]);
    }

    public function process($id = null): ResponseInterface
    {
        try {
            $res = (new CrmImporter())->process((int) $id, CurrentUser::tenantId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->respond(['success' => true, 'data' => $res]);
    }
}
