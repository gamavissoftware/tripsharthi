<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\MessageModel;
use App\Models\TemplateModel;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\TemplateComponentBuilder;

/**
 * Sends the business owner a WhatsApp heads-up about their own pipeline.
 *
 * Separate from the flow engine on purpose: these alerts fire from places that
 * are not flow runs — a redirect handler serving a click in a few hundred
 * milliseconds, for instance — and they are about the owner rather than the
 * contact, so none of the flow context (window state, opt-in, reentry) applies.
 *
 * It is always a template. The owner's own number has no open service window
 * with the business's own WABA, so free-form would simply be refused.
 *
 * Every failure is swallowed and logged. An alert that cannot be delivered must
 * never take down the thing it was reporting on — a prospect clicking a join
 * link has to reach the call whether or not we manage to mention it.
 */
final class OwnerAlertService
{
    public const TEMPLATE = 'gamavis_owner_alert';

    /**
     * @param string $event Reads after the contact's name, e.g.
     *                      "just tapped the join link — they may be in the lobby".
     * @return bool Whether Meta accepted it.
     */
    public function notify(int $tenantId, string $name, string $event, string $number = ''): bool
    {
        $to = $this->alertNumber($tenantId);
        if ($to === null) {
            return false;
        }

        try {
            $template = (new TemplateModel())->setTenant($tenantId)
                ->where('name', self::TEMPLATE)->first();

            if ($template === null || ($template['meta_status'] ?? '') !== 'approved') {
                log_message('warning', '[OwnerAlert] ' . self::TEMPLATE . ' is not approved — alert skipped.');

                return false;
            }

            // Meta rejects an empty body parameter outright.
            $params = array_map(
                static fn ($v) => ['type' => 'text', 'text' => trim($v) === '' ? '-' : $v],
                [$name, $event, $number]
            );

            $client = new CloudApiClient(
                (string) (getenv('WHATSAPP_PHONE_NUMBER_ID') ?: ''),
                (string) (getenv('WHATSAPP_ACCESS_TOKEN') ?: '')
            );
            $result = $client->sendTemplate(
                $to,
                (string) $template['name'],
                (string) ($template['language'] ?? 'en'),
                TemplateComponentBuilder::forSend($template, $params)
            );

            (new MessageModel())->withoutTenantScope()->insert([
                'tenant_id'     => $tenantId,
                'direction'     => 'out',
                'type'          => 'template',
                'category'      => 'utility',
                'body'          => $template['body'] ?? '',
                'wa_message_id' => $result['message_id'] ?? null,
                'status'        => ($result['success'] ?? false) ? 'sent' : 'failed',
                'billable'      => 1,
                'error'         => $result['error'] ?? null,
                'sent_at'       => ($result['success'] ?? false) ? date('Y-m-d H:i:s') : null,
            ]);

            return (bool) ($result['success'] ?? false);
        } catch (\Throwable $e) {
            log_message('error', '[OwnerAlert] failed: ' . $e->getMessage());

            return false;
        }
    }

    /** tenants.settings.alert_number, else OWNER_ALERT_NUMBER in .env. */
    public function alertNumber(int $tenantId): ?string
    {
        try {
            $row = db_connect()->table('tenants')->select('settings')
                ->where('id', $tenantId)->where('deleted_at', null)
                ->get()->getRowArray();

            $settings = json_decode((string) ($row['settings'] ?? ''), true);
            $number   = is_array($settings) ? trim((string) ($settings['alert_number'] ?? '')) : '';
        } catch (\Throwable) {
            $number = '';
        }

        if ($number === '') {
            $number = trim((string) (env('OWNER_ALERT_NUMBER') ?? ''));
        }

        return $number === '' ? null : $number;
    }
}
