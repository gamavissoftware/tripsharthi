<?php

declare(strict_types=1);

namespace App\Services\Commerce;

/**
 * Extracts contact details + a flow trigger from Shopify / WooCommerce webhook
 * payloads. Pure (no DB) so it is fully unit-testable.
 */
class EcommerceContactMapper
{
    /**
     * Map a Shopify webhook topic to a TravelPilot flow trigger type.
     */
    public static function shopifyTrigger(string $topic): ?string
    {
        return match ($topic) {
            'orders/create'                        => 'order_placed',
            'orders/fulfilled', 'fulfillments/create' => 'order_fulfilled',
            'checkouts/create', 'carts/update'     => 'abandoned_cart',
            default                                => null,
        };
    }

    /**
     * Map a WooCommerce webhook topic (+ status) to a flow trigger type.
     */
    public static function wooTrigger(string $topic, array $payload): ?string
    {
        if ($topic === 'order.created') {
            return 'order_placed';
        }
        if ($topic === 'order.updated') {
            $status = strtolower((string) ($payload['status'] ?? ''));
            return in_array($status, ['completed', 'shipped'], true) ? 'order_fulfilled' : null;
        }
        return null;
    }

    /**
     * Extract {phone, name, email} from a Shopify order/checkout payload.
     *
     * @return array{phone:string, name:string, email:string}
     */
    public static function shopifyContact(array $p): array
    {
        $customer = $p['customer'] ?? [];
        $ship     = $p['shipping_address'] ?? [];
        $bill     = $p['billing_address'] ?? [];

        $phone = $p['phone']
            ?? $customer['phone']
            ?? $ship['phone']
            ?? $bill['phone']
            ?? '';

        $name = trim(
            ($customer['first_name'] ?? $ship['first_name'] ?? '')
            . ' ' . ($customer['last_name'] ?? $ship['last_name'] ?? '')
        );

        return [
            'phone' => (string) $phone,
            'name'  => $name !== '' ? $name : (string) ($ship['name'] ?? ''),
            'email' => (string) ($p['email'] ?? $customer['email'] ?? ''),
        ];
    }

    /**
     * Extract {phone, name, email} from a WooCommerce order payload.
     *
     * @return array{phone:string, name:string, email:string}
     */
    public static function wooContact(array $p): array
    {
        $bill = $p['billing'] ?? [];
        $ship = $p['shipping'] ?? [];

        $name = trim(($bill['first_name'] ?? $ship['first_name'] ?? '') . ' ' . ($bill['last_name'] ?? $ship['last_name'] ?? ''));

        return [
            'phone' => (string) ($bill['phone'] ?? ''),
            'name'  => $name,
            'email' => (string) ($bill['email'] ?? ''),
        ];
    }
}
