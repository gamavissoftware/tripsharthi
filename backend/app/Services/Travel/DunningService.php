<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\ActivityModel;
use App\Models\BookingModel;
use App\Models\BookingPaymentModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\DunningSettingModel;
use App\Models\MessageModel;
use App\Models\PaymentReminderModel;
use App\Models\TaskModel;
use App\Models\TemplateModel;
use App\Models\WabaAccountModel;
use App\Services\WhatsApp\BillableComputer;
use App\Services\WhatsApp\TemplateComponentBuilder;
use App\Services\WhatsApp\WindowService;

/**
 * WhatsApp payment dunning for booking instalments.
 *
 * Policy (platform spec §1 applies):
 *  - Reminders are UTILITY messages (transactional, about an existing booking).
 *  - Window open  -> free-form text. Window closed -> an APPROVED utility template only;
 *    with none configured the step is recorded as skipped and a human task is raised —
 *    free-form is never sent outside the window.
 *  - Contacts who opted out (contacts.opt_in = 0) are never messaged; a task is raised instead.
 *  - Each (instalment, step) is reserved in payment_reminders BEFORE sending (UNIQUE key),
 *    so overlapping cron runs cannot double-send.
 *  - Customer messaging stops 45 days past due; a person takes over (see DunningPlanner).
 */
final class DunningService
{
    /** @param null|callable(int):object $clientFactory returns an object with sendText()/sendTemplate() */
    public function __construct(
        private readonly mixed $clientFactory = null,
        private readonly ?int $now = null,
    ) {}

    private function now(): int { return $this->now ?? time(); }

    // ---- settings ---------------------------------------------------------------

    public function settings(int $tenantId): array
    {
        $row = (new DunningSettingModel())->setTenant($tenantId)->first();
        $steps = $row && $row['steps'] ? (json_decode((string) $row['steps'], true) ?: null) : null;
        return [
            'enabled'        => (bool) ($row['enabled'] ?? false),
            'send_from_hour' => (int) ($row['send_from_hour'] ?? 9),
            'send_to_hour'   => (int) ($row['send_to_hour'] ?? 20),
            'steps'          => $steps ?: DunningPlanner::DEFAULT_STEPS,
        ];
    }

    public function saveSettings(int $tenantId, array $in): array
    {
        $cur  = $this->settings($tenantId);
        $from = max(0, min(23, (int) ($in['send_from_hour'] ?? $cur['send_from_hour'])));
        $to   = max($from + 1, min(24, (int) ($in['send_to_hour'] ?? $cur['send_to_hour'])));
        $steps = [];
        foreach ((array) ($in['steps'] ?? $cur['steps']) as $s) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($s['key'] ?? '')));
            if ($key === '') { continue; }
            $steps[] = ['key' => $key, 'offset_days' => (int) ($s['offset_days'] ?? 0),
                'kind' => ($s['kind'] ?? 'message') === 'task' ? 'task' : 'message',
                'template_id' => ! empty($s['template_id']) ? (int) $s['template_id'] : null];
        }
        $data = ['enabled' => ! empty($in['enabled']) ? 1 : 0, 'send_from_hour' => $from, 'send_to_hour' => $to, 'steps' => json_encode($steps ?: DunningPlanner::DEFAULT_STEPS)];
        $m = (new DunningSettingModel())->setTenant($tenantId);
        $row = $m->first();
        if ($row) { (new DunningSettingModel())->setTenant($tenantId)->update((int) $row['id'], $data); }
        else { (new DunningSettingModel())->setTenant($tenantId)->insert($data); }
        return $this->settings($tenantId);
    }

    /** Create the three utility reminder templates as DRAFTS (submit them from Templates). Idempotent. */
    public function setupTemplates(int $tenantId): array
    {
        $defs = [
            'tp_payment_upcoming' => 'Hi {{1}}, a reminder that your payment of {{3}} for booking {{2}} is due on {{4}}. Pay securely here: {{5}}',
            'tp_payment_due'      => 'Hi {{1}}, your payment of {{3}} for booking {{2}} is due on {{4}}. Pay securely here: {{5}}',
            'tp_payment_overdue'  => 'Hi {{1}}, your payment of {{3}} for booking {{2}} was due on {{4}} and is still pending. Pay securely here: {{5}}. If you have already paid, please ignore this message.',
            'tp_payment_receipt'  => 'Hi {{1}}, we have received your payment of {{3}} for booking {{2}} on {{4}}. Thank you!',
        ];
        $out = [];
        foreach ($defs as $name => $body) {
            $existing = (new TemplateModel())->setTenant($tenantId)->where('name', $name)->first();
            if ($existing) { $out[$name] = (int) $existing['id']; continue; }
            $out[$name] = (int) (new TemplateModel())->setTenant($tenantId)->insert([
                'name' => $name, 'display_name' => ucwords(str_replace('_', ' ', substr($name, 3))), 'language' => 'en',
                'category' => 'utility', 'header_type' => 'none', 'body' => $body,
                'variables' => json_encode(['customer_name', 'booking_ref', 'amount', 'date', 'link']), 'meta_status' => 'draft',
            ], true);
        }
        // Wire the default steps to them if the tenant has not chosen templates yet.
        $s = $this->settings($tenantId);
        $map = ['before_3d' => 'tp_payment_upcoming', 'due_today' => 'tp_payment_due', 'overdue_2d' => 'tp_payment_overdue', 'overdue_5d' => 'tp_payment_overdue'];
        foreach ($s['steps'] as &$st) {
            if (empty($st['template_id']) && isset($map[$st['key']])) { $st['template_id'] = $out[$map[$st['key']]]; }
        }
        unset($st);
        $this->saveSettings($tenantId, $s + ['enabled' => $s['enabled']]);
        return $out;
    }

    // ---- scheduled run ----------------------------------------------------------

    /** Run for every tenant that has dunning enabled. @return array<string,int> */
    public function runAll(): array
    {
        $tot = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'tasks' => 0, 'tenants' => 0];
        $rows = (new DunningSettingModel())->withoutTenantScope()->where('enabled', 1)->findAll();
        foreach ($rows as $row) {
            $r = $this->runTenant((int) $row['tenant_id']);
            $tot['tenants']++;
            foreach (['sent', 'skipped', 'failed', 'tasks'] as $k) { $tot[$k] += $r[$k]; }
        }
        return $tot;
    }

    public function runTenant(int $tenantId): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'tasks' => 0];
        $cfg = $this->settings($tenantId);
        if (! $cfg['enabled']) { return $out; }

        (new BookingService())->markOverdue($tenantId);
        $inHours = DunningPlanner::withinSendingHours($this->now(), $cfg['send_from_hour'], $cfg['send_to_hour']);
        $today   = (new \DateTimeImmutable('@' . $this->now()))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d');

        $pays = (new BookingPaymentModel())->setTenant($tenantId)->whereIn('status', ['pending', 'overdue'])
            ->where('due_date <=', date('Y-m-d', strtotime($today . ' +10 days')))->orderBy('due_date')->findAll(500);

        foreach ($pays as $pay) {
            $done = array_column((new PaymentReminderModel())->setTenant($tenantId)
                ->where('booking_payment_id', $pay['id'])->whereIn('status', ['sent', 'skipped', 'reserved'])->findAll(), 'step');
            $plan = DunningPlanner::plan($pay, $today, $cfg['steps'], $done, $this->now());

            foreach ($plan['skip'] as $key) {
                $this->record($tenantId, $pay, $key, 'skipped', 'whatsapp_template', 'Superseded by a later step.');
                $out['skipped']++;
            }
            foreach ($plan['run'] as $step) {
                if (($step['kind'] ?? 'message') === 'task') {
                    $this->raiseTask($tenantId, $pay, $step['key'], 'Payment still unpaid — call the customer.') && $out['tasks']++;
                    continue;
                }
                if (! $inHours) { continue; } // outside sending hours: leave the step for the next run
                $r = $this->sendStep($tenantId, $pay, $step);
                $out[$r === 'sent' ? 'sent' : ($r === 'failed' ? 'failed' : 'skipped')]++;
            }
        }
        return $out;
    }

    // ---- sending ------------------------------------------------------------------

    /** Send one reminder step now. Used by the scheduled run and by the "Remind now" button. @return 'sent'|'failed'|'skipped' */
    public function sendStep(int $tenantId, array $pay, array $step, bool $manual = false): string
    {
        $key = $step['key'];
        $booking = (new BookingModel())->setTenant($tenantId)->find((int) $pay['booking_id']);
        $contact = $booking && $booking['contact_id'] ? (new ContactModel())->setTenant($tenantId)->find((int) $booking['contact_id']) : null;

        // Reserve first (idempotency). A previous *failed* attempt may be retried up to 3 times.
        $rem = (new PaymentReminderModel())->setTenant($tenantId)->where('booking_payment_id', $pay['id'])->where('step', $key)->first();
        if ($rem && ($rem['status'] !== 'failed' || (int) $rem['attempts'] >= 3)) {
            return 'skipped';
        }

        if (! $booking || $booking['status'] === 'cancelled' || ! $contact || empty($contact['wa_number'])) {
            $this->record($tenantId, $pay, $key, 'skipped', 'whatsapp_template', 'No reachable customer on this booking.');
            return 'skipped';
        }
        if ((int) ($contact['opt_in'] ?? 1) === 0) {
            $this->record($tenantId, $pay, $key, 'skipped', 'whatsapp_template', 'Customer opted out of messages.');
            ! $manual && $this->raiseTask($tenantId, $pay, $key . '_optout', 'Customer opted out of WhatsApp — follow up by phone.');
            return 'skipped';
        }

        $id = $this->reserve($tenantId, $pay, $key, $rem);
        if ($id === 0) { return 'skipped'; } // lost the race to another worker

        try {
            $link = null;
            if ($key !== 'receipt') {
                try { $link = (new InstalmentLinkService())->ensure($tenantId, (int) $pay['id']); }
                catch (\RuntimeException $e) { $link = null; } // Razorpay not connected: send without a link
            }
            $vars = [
                'name'   => explode(' ', trim((string) ($contact['name'] ?: 'there')))[0],
                'ref'    => $booking['booking_ref'],
                'amount' => '₹' . number_format(((int) $pay['amount']) / 100, 0, '.', ','),
                'due'    => $key === 'receipt'
                    ? date('j M Y', strtotime((string) ($pay['paid_at'] ?: 'now')))
                    : ($pay['due_date'] ? date('j M Y', strtotime($pay['due_date'])) : ''),
                'link'   => $link['short_url'] ?? '',
            ];
            $res = $this->deliver($tenantId, $contact, $booking, $key, $step, $vars);
        } catch (\Throwable $e) {
            $res = ['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 250)];
        }

        (new PaymentReminderModel())->setTenant($tenantId)->update($id, [
            'status' => $res['status'], 'reason' => $res['reason'] ?? null, 'channel' => $res['channel'] ?? 'whatsapp_template',
            'message_id' => $res['message_id'] ?? null, 'sent_at' => $res['status'] === 'sent' ? date('Y-m-d H:i:s', $this->now()) : null,
        ]);
        if ($res['status'] === 'sent') {
            (new BookingPaymentModel())->setTenant($tenantId)->update((int) $pay['id'], [
                'reminder_count' => (int) $pay['reminder_count'] + 1, 'last_reminded_at' => date('Y-m-d H:i:s', $this->now()),
            ]);
            if ($booking['deal_id']) {
                (new ActivityModel())->log($tenantId, 'whatsapp_out', 'deal', (int) $booking['deal_id'], ['subject' => "Payment reminder sent ({$key})"]);
            }
        } elseif ($res['status'] === 'skipped' && ($res['needs_human'] ?? false) && ! $manual) { // a person pressing "Remind" already knows
            $this->raiseTask($tenantId, $pay, $key . '_notemplate', $res['reason'] ?? 'Send the payment reminder manually.');
        }
        return $res['status'];
    }

    /** @return array{status:string,reason?:string,channel?:string,message_id?:int,needs_human?:bool} */
    private function deliver(int $tenantId, array $contact, array $booking, string $stepKey, array $step, array $vars): array
    {
        $client = $this->client($tenantId);
        $wa     = (string) $contact['wa_number'];

        $conv    = (new ConversationModel())->setTenant($tenantId)->where('wa_number', $wa)->first();
        $convArr = $conv ? (array) $conv : [];
        $open    = $convArr ? (new WindowService(new ConversationModel(), $this->now()))->isOpenForConversation($convArr) : false;
        $convId  = (int) ($convArr['id'] ?? 0) ?: (int) (new ConversationModel())->findOrCreate($tenantId, $wa)['id'];

        if ($open) {
            $body = DunningPlanner::freeFormText($stepKey, $vars);
            $r = $client->sendText($wa, $body);
            $msg = $this->logMessage($tenantId, $contact, $convId, 'text', 'free_form', $body, $r, 0, null);
            return ($r['success'] ?? false)
                ? ['status' => 'sent', 'channel' => 'whatsapp_text', 'message_id' => $msg]
                : ['status' => 'failed', 'channel' => 'whatsapp_text', 'reason' => mb_substr((string) ($r['error'] ?? 'send failed'), 0, 250), 'message_id' => $msg];
        }

        // Window closed -> template only.
        $tpl = ! empty($step['template_id']) ? (new TemplateModel())->setTenant($tenantId)->find((int) $step['template_id']) : null;
        if (! $tpl || $tpl['meta_status'] !== 'approved') {
            return ['status' => 'skipped', 'needs_human' => true,
                'reason' => 'Customer window is closed and no approved utility template is set for this step.'];
        }
        $params = array_map(static fn ($v) => ['type' => 'text', 'text' => $v === '' ? '-' : $v], [$vars['name'], $vars['ref'], $vars['amount'], $vars['due'], $vars['link']]);
        $params = array_slice($params, 0, \App\Services\WhatsApp\TemplateParamCheck::requiredCount($tpl));
        $r = $client->sendTemplate($wa, $tpl['name'], $tpl['language'], TemplateComponentBuilder::forSend($tpl, $params));
        $billable = BillableComputer::compute((string) $tpl['category'], false);
        $msg = $this->logMessage($tenantId, $contact, $convId, 'template', (string) $tpl['category'], (string) $tpl['body'], $r, $billable, (int) $tpl['id']);
        return ($r['success'] ?? false)
            ? ['status' => 'sent', 'channel' => 'whatsapp_template', 'message_id' => $msg]
            : ['status' => 'failed', 'reason' => mb_substr((string) ($r['error'] ?? 'send failed'), 0, 250), 'message_id' => $msg];
    }

    private function logMessage(int $tenantId, array $contact, int $convId, string $type, string $category, string $body, array $r, int $billable, ?int $templateId): int
    {
        $ok = (bool) ($r['success'] ?? false);
        return (int) (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id' => $tenantId, 'contact_id' => $contact['id'], 'conversation_id' => $convId, 'direction' => 'out',
            'type' => $type, 'template_id' => $templateId, 'category' => $category, 'body' => $body,
            'wa_message_id' => $r['message_id'] ?? null, 'status' => $ok ? 'sent' : 'failed', 'billable' => $ok ? $billable : 0,
            'error' => $r['error'] ?? null, 'sent_at' => $ok ? date('Y-m-d H:i:s', $this->now()) : null,
        ], true);
    }

    private function client(int $tenantId): object
    {
        if (is_callable($this->clientFactory)) { return ($this->clientFactory)($tenantId); }
        return \App\Services\Commerce\PaymentLinkService::buildClientForTenant($tenantId);
    }

    // ---- bookkeeping ----------------------------------------------------------------

    private function reserve(int $tenantId, array $pay, string $step, ?array $existing): int
    {
        $now = date('Y-m-d H:i:s', $this->now());
        if ($existing) { // retry of a failed attempt
            (new PaymentReminderModel())->setTenant($tenantId)->update((int) $existing['id'], ['status' => 'reserved', 'attempts' => (int) $existing['attempts'] + 1]);
            return (int) $existing['id'];
        }
        $db = db_connect();
        $db->table('payment_reminders')->ignore(true)->insert([
            'tenant_id' => $tenantId, 'booking_payment_id' => $pay['id'], 'booking_id' => $pay['booking_id'], 'step' => $step,
            'status' => 'reserved', 'attempts' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $db->affectedRows() === 0 ? 0 : (int) $db->insertID();
    }

    private function record(int $tenantId, array $pay, string $step, string $status, string $channel, string $reason): void
    {
        $now = date('Y-m-d H:i:s', $this->now());
        db_connect()->table('payment_reminders')->ignore(true)->insert([
            'tenant_id' => $tenantId, 'booking_payment_id' => $pay['id'], 'booking_id' => $pay['booking_id'], 'step' => $step,
            'channel' => $channel, 'status' => $status, 'reason' => $reason, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Create ONE open human task per (instalment, key). @return bool true when a new task was created */
    private function raiseTask(int $tenantId, array $pay, string $key, string $why): bool
    {
        $now = date('Y-m-d H:i:s', $this->now());
        $db = db_connect();
        $db->table('payment_reminders')->ignore(true)->insert([
            'tenant_id' => $tenantId, 'booking_payment_id' => $pay['id'], 'booking_id' => $pay['booking_id'], 'step' => $key,
            'channel' => 'task', 'status' => 'sent', 'reason' => $why, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($db->affectedRows() === 0) { return false; }
        $booking = (new BookingModel())->setTenant($tenantId)->find((int) $pay['booking_id']);
        (new TaskModel())->setTenant($tenantId)->insert([
            'title' => "Collect ₹" . number_format(((int) $pay['amount']) / 100, 0) . " — {$booking['booking_ref']} ({$pay['label']})",
            'description' => $why, 'type' => 'call', 'status' => 'open', 'priority' => 'high', 'due_at' => $now,
            'assigned_user_id' => $booking['owner_id'] ?? null, 'related_type' => 'deal', 'related_id' => $booking['deal_id'] ?? null,
        ]);
        return true;
    }
}
