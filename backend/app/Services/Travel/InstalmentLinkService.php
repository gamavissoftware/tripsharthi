<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\BookingModel;
use App\Models\BookingPaymentModel;
use App\Models\PaymentLinkModel;
use App\Services\Commerce\PaymentLinkService;

/**
 * One Razorpay payment link per instalment. Reuses PaymentLinkService (tenant's OWN
 * Razorpay account — we are never in the money flow). A live link is reused rather
 * than recreated, so a customer who already has a link in their chat is never handed
 * a second, different one for the same dues.
 */
final class InstalmentLinkService
{
    public function __construct(private readonly ?PaymentLinkService $links = null) {}

    /** @return array{id:int,short_url:string,status:string,amount_paise:int} */
    public function ensure(int $tenantId, int $paymentId): array
    {
        $pay = (new BookingPaymentModel())->setTenant($tenantId)->find($paymentId);
        if (! $pay) {
            throw new \InvalidArgumentException('Payment row not found.');
        }
        if (in_array($pay['status'], ['paid', 'waived'], true)) {
            throw new \DomainException('This instalment is already settled.');
        }
        $booking = (new BookingModel())->setTenant($tenantId)->find((int) $pay['booking_id']);
        if (! $booking || $booking['status'] === 'cancelled') {
            throw new \DomainException('Booking is cancelled.');
        }

        if ($pay['payment_link_id']) {
            $existing = (new PaymentLinkModel())->setTenant($tenantId)->find((int) $pay['payment_link_id']);
            if ($existing && in_array($existing['status'], ['created', 'sent'], true) && (int) $existing['amount_paise'] === (int) $pay['amount']) {
                return $existing;
            }
        }

        $svc  = $this->links ?? new PaymentLinkService();
        $link = $svc->createLink($tenantId, ((int) $pay['amount']) / 100, [
            'contact_id'  => $booking['contact_id'],
            'description' => "{$booking['booking_ref']} – {$pay['label']}",
        ]);
        (new BookingPaymentModel())->setTenant($tenantId)->update($paymentId, ['payment_link_id' => $link['id']]);
        return $link;
    }
}
