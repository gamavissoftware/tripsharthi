<?php

declare(strict_types=1);

namespace App\Models;

class EmailTemplateModel extends BaseModel
{
    protected $table      = 'email_templates';
    protected $primaryKey = 'id';

    protected $allowedFields = ['tenant_id', 'name', 'subject', 'preheader', 'html_body'];

    protected $validationRules = [
        'name'      => 'required|max_length[150]',
        'subject'   => 'required|max_length[255]',
        'html_body' => 'required',
    ];
}
