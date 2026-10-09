<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\IntegrationModel;
use App\Models\MessageModel;
use App\Models\ProductModel;
use App\Services\Auth\CurrentUser;
use App\Services\Commerce\PaymentLinkService;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Product catalog management + single-product WhatsApp sends.
 */
class ProductsController extends ResourceController
{
    protected $format = 'json';

    private function model(): ProductModel
    {
        return (new ProductModel())->setTenant(CurrentUser::tenantId());
    }

    public function index(): ResponseInterface
    {
        $rows = $this->model()
            ->where('status', 'active')
            ->orderBy('created_at', 'DESC')
            ->findAll(200);
        return $this->respond(['success' => true, 'data' => $rows]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate(['retailer_id' => 'required', 'name' => 'required'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }
        try {
            $id = $this->model()->insert($this->payload(), true);
        } catch (\Throwable $e) {
            return $this->fail(['retailer_id' => 'A product with this SKU already exists.'], 422);
        }
        return $this->respondCreated(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        if (! $this->model()->find((int) $id)) {
            return $this->failNotFound("Product #{$id} not found.");
        }
        $this->model()->update((int) $id, $this->payload());
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        if (! $this->model()->find((int) $id)) {
            return $this->failNotFound("Product #{$id} not found.");
        }
        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    // GET /api/v1/products/catalog — current Meta catalog_id
    public function getCatalog(): ResponseInterface
    {
        $integration = (new IntegrationModel())
            ->findActiveByType(CurrentUser::tenantId(), 'whatsapp_catalog');
        $config = $integration ? (json_decode($integration['config'] ?? '{}', true) ?: []) : [];
        return $this->respond(['success' => true, 'data' => ['catalog_id' => $config['catalog_id'] ?? '']]);
    }

    // POST /api/v1/products/catalog — set Meta catalog_id
    public function saveCatalog(): ResponseInterface
    {
        if (! $this->validate(['catalog_id' => 'required|string'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }
        $tenantId  = CurrentUser::tenantId();
        $catalogId = trim((string) $this->request->getJsonVar('catalog_id'));

        $model    = new IntegrationModel();
        $existing = $model->findActiveByType($tenantId, 'whatsapp_catalog');
        if ($existing) {
            $model->setTenant($tenantId)->update((int) $existing['id'], [
                'config' => json_encode(['catalog_id' => $catalogId]), 'status' => 'active',
            ]);
        } else {
            $model->setTenant($tenantId)->insert([
                'type' => 'whatsapp_catalog', 'config' => json_encode(['catalog_id' => $catalogId]), 'status' => 'active',
            ]);
        }
        return $this->respond(['success' => true, 'message' => 'Catalog connected.']);
    }

    // POST /api/v1/products/:id/send — send the product to a contact over WhatsApp
    public function send($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();

        $product = $this->model()->find((int) $id);
        if (! $product) {
            return $this->failNotFound("Product #{$id} not found.");
        }

        $contactId = (int) $this->request->getJsonVar('contact_id');
        $contact   = (new ContactModel())->setTenant($tenantId)->find($contactId);
        if (! $contact) {
            return $this->fail(['contact_id' => 'Contact not found.'], 422);
        }

        $catalogId = $this->catalogId($tenantId);
        if ($catalogId === '') {
            return $this->fail(['catalog' => 'Connect your Meta catalog first (Products → Catalog).'], 422);
        }

        $conv = (new ConversationModel())->findOrCreate($tenantId, $contact['wa_number']);
        if (! (new WindowService(new ConversationModel()))->isOpenForConversation($conv)) {
            return $this->fail(['window' => 'The 24h window is closed — product messages need an open window.'], 422);
        }

        try {
            $client = PaymentLinkService::buildClientForTenant($tenantId);
        } catch (\Throwable $e) {
            return $this->fail(['waba' => $e->getMessage()], 422);
        }

        $body   = $product['name'] . (($product['price_paise'] ?? 0) > 0 ? ' — ₹' . number_format($product['price_paise'] / 100, 2) : '');
        $result = $client->sendProduct($contact['wa_number'], $catalogId, $product['retailer_id'], $body);

        (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => $contactId,
            'conversation_id' => (int) $conv['id'],
            'direction'       => 'out',
            'type'            => 'text',
            'category'        => 'free_form',
            'body'            => '🛍 ' . $body,
            'wa_message_id'   => $result['message_id'] ?? null,
            'status'          => ($result['success'] ?? false) ? 'sent' : 'failed',
            'billable'        => 0,
            'error'           => $result['error'] ?? null,
            'sent_at'         => ($result['success'] ?? false) ? date('Y-m-d H:i:s') : null,
        ]);

        return $this->respond(['success' => (bool) ($result['success'] ?? false), 'message' => ($result['success'] ?? false) ? 'Product sent.' : ($result['error'] ?? 'Send failed.')]);
    }

    private function catalogId(int $tenantId): string
    {
        $integration = (new IntegrationModel())->findActiveByType($tenantId, 'whatsapp_catalog');
        $config = $integration ? (json_decode($integration['config'] ?? '{}', true) ?: []) : [];
        return (string) ($config['catalog_id'] ?? '');
    }

    private function payload(): array
    {
        $price = $this->request->getJsonVar('price');
        return array_filter([
            'retailer_id'  => $this->request->getJsonVar('retailer_id'),
            'name'         => $this->request->getJsonVar('name'),
            'description'  => $this->request->getJsonVar('description'),
            'price_paise'  => $price !== null ? (int) round(((float) $price) * 100) : null,
            'currency'     => $this->request->getJsonVar('currency') ?? 'INR',
            'image_url'    => $this->request->getJsonVar('image_url'),
            'availability' => $this->request->getJsonVar('availability') ?? 'in_stock',
        ], static fn ($v) => $v !== null);
    }
}
