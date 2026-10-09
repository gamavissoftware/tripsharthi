<?php

declare(strict_types=1);

namespace App\Models;

class TeamMemberModel extends BaseModel
{
    protected $table      = 'team_members';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = ['tenant_id', 'team_id', 'user_id'];

    public function forTeam(int $tenantId, int $teamId): array
    {
        return $this->setTenant($tenantId)->where('team_id', $teamId)->findAll();
    }
}
