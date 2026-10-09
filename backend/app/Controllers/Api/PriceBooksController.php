<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\PriceBookEntryModel;
use App\Models\PriceBookModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Price books + their per-product entries (Phase J3). Owner/admin for writes.
 *
 * GET    /crm/price-books                  books, each with entries
 * POST   /crm/price-books                  { name, currency }
 * PUT    /crm/price-books/:id
 * DELETE /crm/price-books/:id
 * POST   /crm/price-books/:id/entries      { product_id, price }   (upsert)
 * DELETE /crm/price-book-entries/:id
 */
class PriceBooksController extends ResourceController
{
    protected $format = 'json';

    private function bm(): PriceBookModel
    {
        return (new PriceBookModel())->setTenant(CurrentUser::tenantId());
    }
    private function em(): PriceBookEntryModel
    {
        return (new PriceBookEntryModel())->setTenant(CurrentUser::tenantId());
    }
    private function denyAgent(): ?ResponseInterface
    {
        $role = (string) (CurrentUser::get()['role'] ?? 'agent');
        return in_array($role, ['owner', 'admin'], true) ? null : $this->failForbidden('Only owners and admins can manage price books.');
    }

    public function index(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $books    = $this->bm()->orderBy('name', 'ASC')->findAll();
        $data = array_map(function ($b) use ($tenantId) {
            $b['entries'] = (new PriceBookEntryModel())->forBook($tenantId, (int) $b['id']);
            return $b;
        }, $books);
        return $this->respond(['success' => true, 'data' => $data]);
    }

    public function create(): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        $name = trim((string) $this->request->getJsonVar('name'));
        if ($name === '') {
            return $this->fail(['name' => 'A name is required.'], 422);
        }
        $id = $this->bm()->insert([
            'name'     => $name,
            'currency' => strtoupper((string) ($this->request->getJsonVar('currency') ?: 'INR')),
        ], true);
        return $this->respondCreated(['success' => true, 'data' => $this->bm()->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        if (! $this->bm()->find((int) $id)) {
            return $this->failNotFound('Price book not found.');
        }
        $payload = [];
        if (($n = $this->request->getJsonVar('name')) !== null) {
            $payload['name'] = trim((string) $n);
        }
        if (($c = $this->request->getJsonVar('currency')) !== null) {
            $payload['currency'] = strtoupper((string) $c);
        }
        if ($payload) {
            $this->bm()->update((int) $id, $payload);
        }
        return $this->respond(['success' => true, 'data' => $this->bm()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        if (! $this->bm()->find((int) $id)) {
            return $this->failNotFound('Price book not found.');
        }
        $this->bm()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    /** Upsert a product price within a book. */
    public function setEntry($bookId = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        if (! $this->bm()->find((int) $bookId)) {
            return $this->failNotFound('Price book not found.');
        }
        $productId = (int) $this->request->getJsonVar('product_id');
        $price     = (int) $this->request->getJsonVar('price');
        if ($productId <= 0) {
            return $this->fail(['product_id' => 'A product is required.'], 422);
        }
        $existing = $this->em()->where('price_book_id', (int) $bookId)->where('product_id', $productId)->first();
        if ($existing) {
            $this->em()->update((int) $existing['id'], ['price_paise' => $price]);
        } else {
            $this->em()->insert(['price_book_id' => (int) $bookId, 'product_id' => $productId, 'price_paise' => $price]);
        }
        return $this->respond(['success' => true]);
    }

    public function deleteEntry($id = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        if (! $this->em()->find((int) $id)) {
            return $this->failNotFound('Entry not found.');
        }
        $this->em()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }
}
