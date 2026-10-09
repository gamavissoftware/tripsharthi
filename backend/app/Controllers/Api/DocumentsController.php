<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\DocumentModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Record document attachments. Files are stored on disk under a server-generated
 * name; downloads are auth-gated and tenant-scoped (no public path is ever
 * exposed). Uploads are whitelisted by detected extension and capped in size.
 */
class DocumentsController extends ResourceController
{
    protected $format = 'json';

    private const RELATED_TYPES = ['contact', 'deal', 'ticket', 'account', 'meeting', 'custom_record'];

    private function model(): DocumentModel
    {
        return (new DocumentModel())->setTenant(CurrentUser::tenantId());
    }

    /** GET /documents?related_type=contact&related_id=5 */
    public function index(): ResponseInterface
    {
        $type = (string) $this->request->getGet('related_type');
        $id   = (int) $this->request->getGet('related_id');
        if (! in_array($type, self::RELATED_TYPES, true) || $id <= 0) {
            return $this->failValidationErrors('related_type and related_id are required.');
        }
        // Never leak stored_path to the client.
        $rows = array_map(static function (array $d): array {
            unset($d['stored_path']);
            return $d;
        }, $this->model()->forRecord(CurrentUser::tenantId(), $type, $id));

        return $this->respond(['success' => true, 'data' => $rows]);
    }

    /** POST /documents  (multipart: file, related_type, related_id) */
    public function create(): ResponseInterface
    {
        $type = (string) $this->request->getPost('related_type');
        $id   = (int) $this->request->getPost('related_id');
        if (! in_array($type, self::RELATED_TYPES, true) || $id <= 0) {
            return $this->failValidationErrors('related_type and related_id are required.');
        }

        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid() || $file->hasMoved()) {
            return $this->failValidationErrors('A valid file is required.');
        }
        if ($file->getSize() > DocumentModel::MAX_BYTES) {
            return $this->failValidationErrors('File exceeds the 10 MB limit.');
        }
        // Trust the DETECTED extension (from mime), not the client-claimed one.
        $ext = strtolower((string) ($file->guessExtension() ?: $file->getClientExtension()));
        if (! in_array($ext, DocumentModel::ALLOWED_EXT, true)) {
            return $this->failValidationErrors('Unsupported file type.');
        }

        $tenantId = CurrentUser::tenantId();
        // store() writes under writable/uploads/<path>/<random>.<ext> and returns the relative path.
        $stored = $file->store('documents/' . $tenantId);
        if ($stored === false) {
            return $this->fail('Could not store the file.', 500);
        }

        $docId = (int) $this->model()->insert([
            'related_type' => $type,
            'related_id'   => $id,
            'filename'     => mb_substr($file->getClientName(), 0, 255),
            'stored_path'  => $stored,
            'mime'         => $file->getClientMimeType(),
            'size_bytes'   => $file->getSize(),
            'uploaded_by'  => CurrentUser::id(),
        ], true);

        $row = $this->model()->find($docId);
        unset($row['stored_path']);
        return $this->respondCreated(['success' => true, 'data' => $row]);
    }

    /** GET /documents/:id/download — streams the file with its original name. */
    public function download($id = null): ResponseInterface
    {
        $doc = $this->model()->find((int) $id);
        if (! $doc) {
            return $this->failNotFound('Document not found.');
        }
        $base = realpath(WRITEPATH . 'uploads');
        $full = realpath(WRITEPATH . 'uploads/' . $doc['stored_path']);
        // Defensive: the resolved path must stay inside the uploads dir.
        if ($full === false || $base === false || ! str_starts_with($full, $base) || ! is_file($full)) {
            return $this->failNotFound('File is no longer available.');
        }
        return $this->response->download($full, null)->setFileName($doc['filename']);
    }

    /** DELETE /documents/:id — soft-delete the row and remove the file. */
    public function delete($id = null): ResponseInterface
    {
        $doc = $this->model()->find((int) $id);
        if (! $doc) {
            return $this->failNotFound('Document not found.');
        }
        $this->model()->delete((int) $id);
        $full = realpath(WRITEPATH . 'uploads/' . $doc['stored_path']);
        $base = realpath(WRITEPATH . 'uploads');
        if ($full !== false && $base !== false && str_starts_with($full, $base) && is_file($full)) {
            @unlink($full);
        }
        return $this->respondDeleted(['success' => true]);
    }
}
