<?php

declare(strict_types=1);

namespace App\Models;

class PriceBookEntryModel extends BaseModel
{
    protected $table      = 'price_book_entries';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = ['tenant_id', 'price_book_id', 'product_id', 'price_paise'];

    public function forBook(int $tenantId, int $bookId): array
    {
        return $this->setTenant($tenantId)->where('price_book_id', $bookId)->findAll();
    }
}
