<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\BookingPaymentModel;
use App\Models\PaymentReminderModel;
use App\Models\TemplateModel;
use App\Services\Auth\CurrentUser;
use App\Services\Travel\DunningService;
use App\Services\Travel\InstalmentLinkService;

/** Payment links + WhatsApp dunning controls. */
class DunningController extends TravelBaseController
{
    /** GET /dunning/settings — settings plus the utility templates a step can use. */
    public function settings()
    {
        $tid = CurrentUser::tenantId();
        $tpls = (new TemplateModel())->setTenant($tid)->where('category', 'utility')->findAll(100);
        return $this->ok((new DunningService())->settings($tid) + ['templates' => array_map(
            static fn ($t) => ['id' => $t['id'], 'name' => $t['name'], 'meta_status' => $t['meta_status']], $tpls)]);
    }

    /** PUT /dunning/settings */
    public function save()
    {
        return $this->ok((new DunningService())->saveSettings(CurrentUser::tenantId(), $this->body()));
    }

    /** POST /dunning/setup-templates — create the draft utility templates (submit them from Templates). */
    public function setupTemplates()
    {
        return $this->ok((new DunningService())->setupTemplates(CurrentUser::tenantId()), 201);
    }

    /** POST /bookings/payments/:id/link — create (or reuse) the Razorpay link for an instalment. */
    public function link($paymentId = null)
    {
        try {
            $l = (new InstalmentLinkService())->ensure(CurrentUser::tenantId(), (int) $paymentId);
        } catch (\InvalidArgumentException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (\DomainException | \RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(['id' => $l['id'], 'short_url' => $l['short_url'], 'status' => $l['status']]);
    }

    /** POST /bookings/payments/:id/remind — send a reminder now (manual; ignores sending hours). */
    public function remind($paymentId = null)
    {
        $tid = CurrentUser::tenantId();
        $pay = (new BookingPaymentModel())->setTenant($tid)->find((int) $paymentId);
        if (! $pay) { return $this->failNotFound('Payment row not found.'); }
        if (in_array($pay['status'], ['paid', 'waived'], true)) { return $this->fail('This instalment is already settled.', 409); }
        $svc  = new DunningService();
        $step = ['key' => 'manual_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)), 'kind' => 'message',
            'template_id' => $this->templateFor($svc->settings($tid)['steps'], (int) $pay['id'])];
        $res = $svc->sendStep($tid, $pay, $step, true);
        $rem = (new PaymentReminderModel())->setTenant($tid)->where('booking_payment_id', $pay['id'])->where('step', $step['key'])->first();
        if ($res !== 'sent') {
            return $this->fail($rem['reason'] ?? 'Could not send the reminder.', 422);
        }
        return $this->ok(['sent' => true, 'channel' => $rem['channel'] ?? null]);
    }

    /** GET /bookings/payments/:id/reminders */
    public function reminders($paymentId = null)
    {
        $rows = (new PaymentReminderModel())->setTenant(CurrentUser::tenantId())->where('booking_payment_id', (int) $paymentId)->orderBy('id', 'DESC')->findAll(50);
        return $this->ok($rows);
    }

    /** Pick the overdue/due template for a manual reminder: the most-overdue configured one. */
    private function templateFor(array $steps, int $paymentId): ?int
    {
        $best = null;
        foreach ($steps as $s) {
            if (($s['kind'] ?? 'message') === 'message' && ! empty($s['template_id']) && ($best === null || $s['offset_days'] > $best['offset_days'])) { $best = $s; }
        }
        return $best['template_id'] ?? null;
    }
}
