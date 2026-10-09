<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use CodeIgniter\Validation\ValidationInterface;

/**
 * Tenant model — not itself tenant-scoped (tenants own themselves).
 */
class TenantModel extends Model
{
    protected $table      = 'tenants';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;

    protected $allowedFields = [
        'name',
        'slug',
        'plan',
        'status',
        'mode',
        'settings',
        'record_visibility',
    ];

    protected $validationRules = [
        'name' => 'required|max_length[255]',
        'slug' => 'required|max_length[100]|is_unique[tenants.slug,id,{id}]',
        'plan' => 'in_list[free,starter,growth,pro]',
    ];

    public function findBySlug(string $slug): array|object|null
    {
        return $this->where('slug', $slug)->first();
    }
}
