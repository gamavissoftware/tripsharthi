<?php

declare(strict_types=1);

namespace App\Models;

class ProductModel extends BaseModel
{
    protected $table      = 'products';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'retailer_id', 'name', 'description',
        'price_paise', 'currency', 'image_url',
        'availability', 'meta_product_id', 'status',
    ];

    protected $validationRules = [
        'retailer_id' => 'required|max_length[120]',
        'name'        => 'required|max_length[255]',
    ];

    public function findByRetailerId(string $retailerId): array|object|null
    {
        $this->scopeTenant();
        return $this->where('retailer_id', $retailerId)->first();
    }
}
