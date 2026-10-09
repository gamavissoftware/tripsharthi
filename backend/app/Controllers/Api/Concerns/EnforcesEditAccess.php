<?php

declare(strict_types=1);

namespace App\Controllers\Api\Concerns;

use App\Models\BaseModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Record-level write guard (Phase M, read-only shares). Call denyIfReadOnly()
 * at the top of update/delete/move on an ownable entity, AFTER the existence
 * check, so a record an agent can only *see* (via a read-access share) returns
 * 403 instead of being editable. canEdit() is a no-op for system/CLI, owner/admin,
 * and tenants in `open` mode, so this is inert until a tenant restricts visibility.
 */
trait EnforcesEditAccess
{
    protected function denyIfReadOnly(BaseModel $model, int $id): ?ResponseInterface
    {
        if (! $model->canEdit($id)) {
            return $this->failForbidden('You have read-only access to this record.');
        }
        return null;
    }
}
