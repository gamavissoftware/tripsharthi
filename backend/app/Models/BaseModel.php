<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Auth\CurrentUser;
use App\Services\Crm\TeamVisibilityService;
use CodeIgniter\Model;
use CodeIgniter\Validation\ValidationInterface;

/**
 * Tenant-aware base model.
 *
 * Every subclass that holds business data must call setTenant() before any
 * query so the tenant_id scope is enforced automatically on reads and injected
 * automatically on writes.
 *
 * Usage:
 *   $model = new ContactModel();
 *   $model->setTenant($tenantId)->findAll();
 */
abstract class BaseModel extends Model
{
    protected int  $tenantId           = 0;
    protected bool $bypassTenantScope  = false;

    /**
     * Record-level permissions (Phase M). A subclass holding user-owned records
     * sets $ownable = true and $entityType to its record_shares key. Reads are
     * then scoped to records the acting agent owns / shares / (in team mode) a
     * teammate owns — unless the tenant is in 'open' mode, the caller is
     * owner/admin, or there is no authenticated user (system/CLI/webhook).
     */
    protected bool   $ownable               = false;
    protected string $entityType            = '';
    protected bool   $bypassVisibilityScope = false;

    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;

    public function __construct(?ValidationInterface $validation = null)
    {
        parent::__construct($validation);
        // Prepend tenant injection so child callbacks run after.
        array_unshift($this->beforeInsert, 'injectTenantId');
        array_unshift($this->beforeUpdate, 'injectTenantId');
    }

    public function setTenant(int $tenantId): static
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    /**
     * Bypass the tenant scope for the NEXT query only.
     *
     * Use only for intentional cross-tenant reads (e.g. auth token lookup).
     * The flag auto-resets after one scopeTenant() call.
     */
    public function withoutTenantScope(): static
    {
        $this->bypassTenantScope = true;
        return $this;
    }

    // ------------------------------------------------------------------
    // Tenant scope helpers
    // ------------------------------------------------------------------

    protected function scopeTenant(): void
    {
        if ($this->bypassTenantScope) {
            $this->bypassTenantScope = false; // auto-reset after one use
            return;
        }

        if ($this->tenantId <= 0) {
            throw new \RuntimeException(
                static::class . '::setTenant() must be called before any query. '
                . 'Use withoutTenantScope() for intentional cross-tenant queries.'
            );
        }

        $this->where($this->table . '.tenant_id', $this->tenantId);
    }

    protected function injectTenantId(array $data): array
    {
        if ($this->tenantId > 0 && isset($data['data'])) {
            $data['data']['tenant_id'] = $this->tenantId;
        }
        return $data;
    }

    // ------------------------------------------------------------------
    // Record-level visibility scope (Phase M)
    // ------------------------------------------------------------------

    /**
     * Bypass the visibility scope for the NEXT query only — for intentional
     * cross-owner reads (merge, recycle-bin restore, admin tooling). Auto-resets.
     */
    public function withoutVisibilityScope(): static
    {
        $this->bypassVisibilityScope = true;
        return $this;
    }

    /**
     * True when the acting agent may edit/delete record $id. Editable when they
     * own it (or a teammate does, in team mode) or hold an explicit `edit` share.
     * A record visible only through a `read` share is NOT editable → controllers
     * return 403. System/CLI, owner/admin, and `open` mode are always allowed.
     */
    public function canEdit(int $id): bool
    {
        if (! $this->ownable || ! CurrentUser::isAuthenticated() || CurrentUser::isPrivileged()) {
            return true;
        }
        if (CurrentUser::recordVisibility() === 'open') {
            return true;
        }

        $uid = CurrentUser::id();
        $row = $this->withoutVisibilityScope()->find($id);
        if ($row === null) {
            return false; // not visible/doesn't exist — controller 404s on the find anyway
        }

        $visibleIds = CurrentUser::recordVisibility() === 'team'
            ? (new TeamVisibilityService())->visibleUserIds($this->tenantId, $uid)
            : [$uid];
        $ownerId = (int) ((is_array($row) ? $row['owner_id'] : $row->owner_id) ?? 0);
        if (in_array($ownerId, $visibleIds, true)) {
            return true; // own (or team-owned) record
        }

        // Otherwise editable only via an explicit edit-access share.
        return in_array($id, $this->sharedEntityIds($uid, 'edit'), true);
    }

    protected function scopeVisibility(): void
    {
        if ($this->bypassVisibilityScope) {
            $this->bypassVisibilityScope = false;
            return;
        }
        if (! $this->ownable || ! CurrentUser::isAuthenticated() || CurrentUser::isPrivileged()) {
            return; // system/CLI/webhook and owner/admin see everything
        }
        if (CurrentUser::recordVisibility() === 'open') {
            return;
        }

        $uid        = CurrentUser::id();
        $visibleIds = CurrentUser::recordVisibility() === 'team'
            ? (new TeamVisibilityService())->visibleUserIds($this->tenantId, $uid)
            : [$uid];

        $sharedIds = $this->sharedEntityIds($uid);

        $this->groupStart()->whereIn($this->table . '.owner_id', $visibleIds);
        if ($sharedIds !== []) {
            $this->orWhereIn($this->table . '.id', $sharedIds);
        }
        $this->groupEnd();
    }

    /**
     * Record ids of this entity explicitly shared with the user or one of their
     * teams. When $access is 'edit', only edit-access shares count (read shares
     * grant visibility but not write).
     */
    private function sharedEntityIds(int $userId, ?string $access = null): array
    {
        if ($this->entityType === '') {
            return [];
        }
        $db      = db_connect();
        $teamIds = array_column(
            $db->table('team_members')->select('team_id')
                ->where('tenant_id', $this->tenantId)->where('user_id', $userId)->get()->getResultArray(),
            'team_id'
        );

        $q = $db->table('record_shares')->select('entity_id')
            ->where('tenant_id', $this->tenantId)
            ->where('entity_type', $this->entityType)
            ->where('deleted_at', null);
        if ($access !== null) {
            $q->where('access', $access);
        }
        $q
            ->groupStart()
                ->groupStart()->where('grantee_type', 'user')->where('grantee_id', $userId)->groupEnd();
        if ($teamIds !== []) {
            $q->orGroupStart()->where('grantee_type', 'team')->whereIn('grantee_id', $teamIds)->groupEnd();
        }
        $q->groupEnd();

        return array_map('intval', array_column($q->get()->getResultArray(), 'entity_id'));
    }

    // ------------------------------------------------------------------
    // Scoped read overrides
    // ------------------------------------------------------------------

    public function findAll(?int $limit = null, int $offset = 0): array
    {
        $this->scopeTenant();
        $this->scopeVisibility();
        return parent::findAll($limit, $offset);
    }

    public function find(mixed $id = null): array|object|null
    {
        $this->scopeTenant();
        $this->scopeVisibility();
        return parent::find($id);
    }

    public function first(): array|object|null
    {
        $this->scopeTenant();
        $this->scopeVisibility();
        return parent::first();
    }

    public function paginate(?int $perPage = null, string $group = 'default', ?int $page = null, int $segment = 0): ?array
    {
        $this->scopeTenant();
        $this->scopeVisibility();
        return parent::paginate($perPage, $group, $page, $segment);
    }
}
