<?php

declare(strict_types=1);

namespace App\Models;

class TeamModel extends BaseModel
{
    protected $table      = 'teams';
    protected $primaryKey = 'id';

    protected $allowedFields = ['tenant_id', 'name'];
}
