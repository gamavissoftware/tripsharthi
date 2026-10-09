<?php

declare(strict_types=1);

namespace App\Models;

class TagModel extends BaseModel
{
    protected $table      = 'tags';
    protected $primaryKey = 'id';

    protected $allowedFields = ['tenant_id', 'name', 'color'];

    protected $validationRules = [
        'name'  => 'required|max_length[50]',
        'color' => 'permit_empty|regex_match[/^#[0-9a-fA-F]{6}$/]',
    ];
}
