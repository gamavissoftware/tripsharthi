<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Services\Flow\FlowTriggerService;
use App\Services\Leads\ContactDedupeService;
use App\Services\Leads\WaNumberNormalizer;

/**
 * Turns a verified Shopify / WooCommerce webhook into a contact + flow trigger.
 *
 * Order/checkout events become flow triggers (order_placed, order_fulfilled,
 * abandoned_cart) so merchants build the actual WhatsApp message in the flow
 * builder — abandoned-cart recovery, order confirmations, shipping updates.
 */
class EcommerceWebhookService
{
    /**
     * @return array{contact_id:int, trigger:?string, enqueued:int}
     */
    public function process(int $tenantId, string $platform, string $topic, array $payload, string $defaultCountry = ''): array
    {
        $trigger = $platform === 'shopify'
            ? EcommerceContactMapper::shopifyTrigger($topic)
            : EcommerceContactMapper::wooTrigger($topic, $payload);

        $contact = $platform === 'shopify'
            ? EcommerceContactMapper::shopifyContact($payload)
            : EcommerceContactMapper::wooContact($payload);

        $waNumber = $this->normalize($contact['phone'], $defaultCountry);
        if ($waNumber === '') {
            log_message('info', "Ecommerce[{$platform}]: order had no usable phone — contact not created.");
            return ['contact_id' => 0, 'trigger' => $trigger, 'enqueued' => 0];
        }

        $dedupe = new ContactDedupeService(new ContactModel(), new ContactFieldValueModel());
        $result = $dedupe->upsert($tenantId, [
            'wa_number' => $waNumber,
            'name'      => $contact['name'] ?: null,
            'email'     => $contact['email'] ?: null,
            'source'    => $platform, // 'shopify' | 'woocommerce'
            'opt_in'    => 1,         // transactional customer
        ]);
        $contactId = (int) $result['contact_id'];

        $enqueued = 0;
        if ($trigger !== null && $contactId > 0) {
            $enqueued = FlowTriggerService::fire($trigger, $tenantId, $contactId, [
                'platform' => $platform,
                'topic'    => $topic,
                'order_id' => (string) ($payload['id'] ?? $payload['order_number'] ?? ''),
                'total'    => (string) ($payload['total_price'] ?? $payload['total'] ?? ''),
            ]);
        }

        return ['contact_id' => $contactId, 'trigger' => $trigger, 'enqueued' => $enqueued];
    }

    /**
     * Normalise a raw store phone to E.164, prepending the tenant's default
     * country code when the number arrives without one.
     */
    private function normalize(string $raw, string $defaultCountry): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if ($raw[0] !== '+' && $defaultCountry !== '') {
            $digits = preg_replace('/\D/', '', $raw);
            $digits = ltrim($digits, '0');
            $cc     = ltrim($defaultCountry, '+');
            $raw    = '+' . $cc . $digits;
        }
        return WaNumberNormalizer::normalize($raw);
    }
}
