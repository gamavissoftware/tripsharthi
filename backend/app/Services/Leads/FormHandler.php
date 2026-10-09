<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\WebFormModel;
use App\Services\Flow\FlowTriggerService;

/**
 * Handles public web form submissions.
 *
 * Finds the form by token, validates required fields, upserts the contact
 * via ContactDedupeService, and returns the result.
 *
 * Sprint 4: fire 'form_submitted' event here for flow triggers.
 */
class FormHandler
{
    public function __construct(
        private WebFormModel           $webFormModel,
        private ContactModel           $contactModel,
        private ContactFieldValueModel $cfvModel,
    ) {}

    /**
     * @param  string $formToken
     * @param  array  $postData   Raw $_POST / JSON body
     * @return array{ok:bool, contact_id?:int, action?:string, errors?:array, redirect_url?:string}
     */
    public function handle(string $formToken, array $postData): array
    {
        $form = $this->webFormModel->findByToken($formToken);

        if ($form === null) {
            return ['ok' => false, 'errors' => ['form' => 'Form not found.']];
        }

        if (! (bool) $form['active']) {
            return ['ok' => false, 'errors' => ['form' => 'This form is no longer active.']];
        }

        $tenantId = (int) $form['tenant_id'];
        $fields   = WebFormModel::normalizeFields($form['fields'] ?? null);

        // Validate required fields
        $validationErrors = [];
        foreach ($fields as $fieldDef) {
            if (! empty($fieldDef['required'])) {
                $key = $fieldDef['field_key'];
                if (empty(trim((string) ($postData[$key] ?? '')))) {
                    $validationErrors[$key] = ($fieldDef['label'] ?? $key) . ' is required.';
                }
            }
        }

        if (! empty($validationErrors)) {
            return ['ok' => false, 'errors' => $validationErrors];
        }

        // Build contact data from submitted fields
        $standardFields = ['wa_number', 'name', 'email', 'status', 'source', 'opt_in'];
        $contactData    = ['source' => 'web_form', 'custom_fields' => []];

        foreach ($fields as $fieldDef) {
            $key   = $fieldDef['field_key'];
            $value = trim((string) ($postData[$key] ?? ''));
            if ($value !== '') {
                if (in_array($key, $standardFields, true)) {
                    $contactData[$key] = $value;
                } else {
                    $contactData['custom_fields'][$key] = $value;
                }
            }
        }

        // Normalize wa_number
        $waRaw    = $contactData['wa_number'] ?? '';
        $waNumber = WaNumberNormalizer::normalize($waRaw);

        if ($waNumber === '') {
            return ['ok' => false, 'errors' => ['wa_number' => 'Please enter a valid WhatsApp number.']];
        }

        $contactData['wa_number'] = $waNumber;

        $dedupe = new ContactDedupeService($this->contactModel, $this->cfvModel);
        $contactData['_attribution'] = \App\Services\Travel\AttributionService::fromForm($postData);
        $result = $dedupe->upsert($tenantId, $contactData);

        // Fire form_submitted trigger (replaces Sprint 1 no-op).
        // Contact upsert is auto-committed above — safe to enqueue now.
        FlowTriggerService::fire('form_submitted', $tenantId, $result['contact_id'], [
            'form_id'    => (int) $form['id'],
            'form_token' => $form['form_token'] ?? '',
        ]);

        return [
            'ok'           => true,
            'contact_id'   => $result['contact_id'],
            'action'       => $result['action'],
            'redirect_url' => $form['redirect_url'] ?? null,
        ];
    }
}
