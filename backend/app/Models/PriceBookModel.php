<?php

declare(strict_types=1);

namespace App\Models;

class PriceBookModel extends BaseModel
{
    protected $table      = 'price_books';
    protected $primaryKey = 'id';

    protected $allowedFields = ['tenant_id', 'name', 'currency', 'is_default'];
}
