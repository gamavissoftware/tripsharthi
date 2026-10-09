<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Libraries\OptionalId;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\TemplateModel;
use App\Models\WabaAccountModel;
use App\Services\Auth\CurrentUser;
use App\Services\Leads\ContactDedupeService;
use App\Services\Leads\ContactExporter;
use App\Services\Leads\WaNumberNormalizer;
use App\Services\WhatsApp\BillableComputer;
use App\Services\WhatsApp\TemplateComponentBuilder;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use App\Controllers\Api\Concerns\EnforcesEditAccess;

class ContactsController extends ResourceController
{
    use EnforcesEditAccess;

    protected $format = 'json';

    // GET /api/v1/contacts
    public function index(): ResponseInterface
    {
        $model   = (new ContactModel())->setTenant(CurrentUser::tenantId());
        $filters = [
            'status' => $this->request->getGet('status'),
            'source' => $this->request->getGet('source'),
            'q'      => $this->request->getGet('q'),
            'tag_id'   => $this->request->getGet('tag_id'),
            'category' => $this->request->getGet('category'),
        ];

        $perPage  = (int) ($this->request->getGet('per_page') ?? 25);
        $contacts = $model->listWithTags(array_filter($filters), $perPage);

        return $this->respond([
            'success' => true,
            'data'    => $contacts,
            'pager'   => $model->pager?->getDetails(),
        ]);
    }

    // GET /api/v1/contacts/categories — distinct company categories, for the list filter
    public function categories(): ResponseInterface
    {
        $categories = (new ContactModel())->distinctCategories(CurrentUser::tenantId());

        return $this->respond(['success' => true, 'data' => $categories]);
    }

    // GET /api/v1/contacts/export — CSV download honouring list filters
    public function export(): ResponseInterface
    {
        $filters = array_filter([
            'status' => $this->request->getGet('status'),
            'source' => $this->request->getGet('source'),
            'q'      => $this->request->getGet('q'),
            'tag_id'   => $this->request->getGet('tag_id'),
            'category' => $this->request->getGet('category'),
        ], static fn ($v) => $v !== null && $v !== '');

        $csv = (new ContactExporter(new ContactModel()))
            ->export(CurrentUser::tenantId(), $filters);

        $filename = 'contacts-export-' . date('Ymd-His') . '.csv';

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    // GET /api/v1/contacts/:id
    public function show($id = null): ResponseInterface
    {
        $model   = (new ContactModel())->setTenant(CurrentUser::tenantId());
        $contact = $model->find((int) $id);
        if (! $contact) {
            return $this->failNotFound("Contact #{$id} not found.");
        }

        $tags   = $model->getTagsFor((int) $id);
        // Restricted custom fields are visible only to owners/admins.
        $role   = CurrentUser::get()['role'] ?? 'agent';
        $cfv    = (new ContactFieldValueModel())->getForContact(
            (int) $id,
            in_array($role, ['owner', 'admin'], true)
        );

        $contact = is_array($contact) ? $contact : (array) $contact;
        $company = $model->companyFor(CurrentUser::tenantId(), (int) ($contact['account_id'] ?? 0));

        return $this->respond([
            'success' => true,
            'data'    => array_merge(
                $contact,
                [
                    'tags'             => $tags,
                    'custom_fields'    => $cfv,
                    // Flattened alongside the nested object: the profile header
                    // reads the flat keys, the same names the list endpoint uses.
                    'company'          => $company,
                    'company_name'     => $company['name']     ?? null,
                    'company_industry' => $company['industry'] ?? null,
                ]
            ),
        ]);
    }

    // POST /api/v1/contacts
    public function create(): ResponseInterface
    {
        $raw     = trim((string) ($this->request->getJsonVar('wa_number') ?? ''));
        $email   = trim((string) ($this->request->getJsonVar('email') ?? ''));

        // A contact needs at least one identity: a WhatsApp number or an email.
        if ($raw === '' && $email === '') {
            return $this->fail(['wa_number' => 'Provide a WhatsApp number or an email.'], 422);
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail(['email' => 'Invalid email address.'], 422);
        }

        // Normalize the number only when one was given; email-only contacts store NULL.
        $waNumber = '';
        if ($raw !== '') {
            $waNumber = WaNumberNormalizer::normalize($raw);
            if ($waNumber === '') {
                return $this->fail(['wa_number' => 'Invalid WhatsApp number format.'], 422);
            }
        }

        $dedupe = new ContactDedupeService(
            new ContactModel(),
            new ContactFieldValueModel()
        );

        $srcAllowed = ['manual', 'csv_import', 'web_form', 'meta_lead_ads', 'google_lead_forms', 'whatsapp_inbound', 'email_inbound', 'shopify', 'woocommerce', 'portal'];
        $source     = (string) ($this->request->getJsonVar('source') ?? 'manual');
        if (! in_array($source, $srcAllowed, true)) {
            $source = 'manual';
        }

        $data = [
            'wa_number'     => $waNumber,
            'name'          => $this->request->getJsonVar('name')   ?? null,
            'email'         => $email !== '' ? $email : null,
            'status'        => $this->request->getJsonVar('status') ?? 'new',
            'source'        => $source,
            // getJsonVar returns stdClass for a JSON object unless assoc is
            // forced; without `true` the downstream is_array() guard fails and
            // custom fields are silently dropped.
            'custom_fields' => $this->request->getJsonVar('custom_fields', true) ?? [],
            // upsert() resolves these into an account and links account_id —
            // the same path the importer takes, so a company typed here and one
            // arriving in a CSV land on the same row.
            'company'          => $this->request->getJsonVar('company') ?? null,
            'company_industry' => $this->request->getJsonVar('business_type') ?? null,
        ];

        $result = $dedupe->upsert(CurrentUser::tenantId(), $data);

        $model   = (new ContactModel())->setTenant(CurrentUser::tenantId());

        // Persist optional CRM / qualification fields the dedupe upsert doesn't carry.
        $extra = array_filter([
            'account_id'           => OptionalId::from($this->request->getJsonVar('account_id')),
            'owner_id'             => OptionalId::from($this->request->getJsonVar('owner_id')),
            'job_title'            => $this->request->getJsonVar('job_title'),
            'lifecycle_stage'      => $this->request->getJsonVar('lifecycle_stage'),
            'phone_secondary'      => $this->request->getJsonVar('phone_secondary'),
            'city'                 => $this->request->getJsonVar('city'),
            'state'                => $this->request->getJsonVar('state'),
            'country'              => $this->request->getJsonVar('country'),
            'business_type'        => $this->request->getJsonVar('business_type'),
            'requirement_type'     => $this->request->getJsonVar('requirement_type'),
            'current_process'      => $this->request->getJsonVar('current_process'),
            'budget_amount'        => OptionalId::amount($this->request->getJsonVar('budget_amount')),
            'timeline'             => $this->request->getJsonVar('timeline'),
            'qualification_status' => $this->request->getJsonVar('qualification_status'),
            'ai_call_status'       => $this->request->getJsonVar('ai_call_status'),
            'priority'             => $this->request->getJsonVar('priority'),
            'remarks'              => $this->request->getJsonVar('remarks'),
        ], static fn ($v) => $v !== null);
        if ($extra !== []) {
            $model->update((int) $result['contact_id'], $extra);
        }

        \App\Services\Crm\LeadScoringService::recalcQuietly((int) $result['contact_id'], CurrentUser::tenantId());
        $contact = $model->find($result['contact_id']);
        \App\Services\Crm\AuditLogger::log($result['action'] === 'inserted' ? 'created' : 'updated', 'contact', (int) $result['contact_id'], null, $contact);

        $code = $result['action'] === 'inserted' ? 201 : 200;
        return $this->respond([
            'success' => true,
            'data'    => $contact,
            'action'  => $result['action'],
        ], $code);
    }

    // PUT /api/v1/contacts/:id
    public function update($id = null): ResponseInterface
    {
        $model   = (new ContactModel())->setTenant(CurrentUser::tenantId());
        $contact = $model->find((int) $id);
        if (! $contact) {
            return $this->failNotFound("Contact #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;

        $payload = array_filter([
            'name'            => $this->request->getJsonVar('name'),
            'email'           => $this->request->getJsonVar('email'),
            'language'        => $this->request->getJsonVar('language'),
            'status'          => $this->request->getJsonVar('status'),
            'source'          => $this->request->getJsonVar('source'),
            'opt_in'          => $this->request->getJsonVar('opt_in'),
            // CRM fields (Phase A)
            'account_id'      => OptionalId::from($this->request->getJsonVar('account_id')),
            'owner_id'        => OptionalId::from($this->request->getJsonVar('owner_id')),
            'job_title'       => $this->request->getJsonVar('job_title'),
            'lifecycle_stage' => $this->request->getJsonVar('lifecycle_stage'),
            // lead_score is engine-computed (Phase H1) — not client-settable; recalc owns it.
            'phone_secondary' => $this->request->getJsonVar('phone_secondary'),
            // Lead qualification (CRM richness)
            'city'                 => $this->request->getJsonVar('city'),
            'state'                => $this->request->getJsonVar('state'),
            'country'              => $this->request->getJsonVar('country'),
            'business_type'        => $this->request->getJsonVar('business_type'),
            'requirement_type'     => $this->request->getJsonVar('requirement_type'),
            'current_process'      => $this->request->getJsonVar('current_process'),
            'budget_amount'        => OptionalId::amount($this->request->getJsonVar('budget_amount')),
            'timeline'             => $this->request->getJsonVar('timeline'),
            'qualification_status' => $this->request->getJsonVar('qualification_status'),
            'ai_call_status'       => $this->request->getJsonVar('ai_call_status'),
            'priority'             => $this->request->getJsonVar('priority'),
            'remarks'              => $this->request->getJsonVar('remarks'),
        ], static fn ($v) => $v !== null);

        // Company by name — create-or-link, the same resolution the importer
        // uses. Applied after the array_filter above so an explicit unlink
        // survives: clearing the field on the form is deliberate, unlike an
        // import row that simply omits the column.
        $companyRaw = $this->request->getJsonVar('company');
        if ($companyRaw !== null && ! is_array($companyRaw)) {
            $companyName = trim((string) $companyRaw);
            $payload['account_id'] = $companyName === ''
                ? null
                : (new \App\Services\Crm\AccountResolver())->resolve(
                    CurrentUser::tenantId(),
                    $companyName,
                    (string) ($this->request->getJsonVar('business_type') ?? '')
                );
        }

        if (! empty($payload)) {
            $model->setTenant(CurrentUser::tenantId())->update((int) $id, $payload);
            \App\Services\Crm\LeadScoringService::recalcQuietly((int) $id, CurrentUser::tenantId());
        }

        // Custom field values
        // assoc=true — without it a JSON object arrives as stdClass and the
        // is_array() guard silently skipped saving custom field values.
        $customFields = $this->request->getJsonVar('custom_fields', true);
        if (is_array($customFields)) {
            (new ContactFieldValueModel())->bulkSetForContact(
                (int) $id, CurrentUser::tenantId(), $customFields
            );
        }

        $after = $model->find((int) $id);
        \App\Services\Crm\AuditLogger::log('updated', 'contact', (int) $id, $contact, $after);
        return $this->respond(['success' => true, 'data' => $after]);
    }

    // DELETE /api/v1/contacts/:id
    public function delete($id = null): ResponseInterface
    {
        $model  = (new ContactModel())->setTenant(CurrentUser::tenantId());
        $before = $model->find((int) $id);
        if (! $before) {
            return $this->failNotFound("Contact #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;
        $model->setTenant(CurrentUser::tenantId())->delete((int) $id);
        \App\Services\Crm\AuditLogger::log('deleted', 'contact', (int) $id, $before, null);
        return $this->respondDeleted(['success' => true]);
    }

    // GET /api/v1/contacts/:id/messaging — can we free-form, or template-only?
    public function messaging($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $contact  = (new ContactModel())->setTenant($tenantId)->find((int) $id);
        if (! $contact) {
            return $this->failNotFound("Contact #{$id} not found.");
        }

        $conv = (new ConversationModel())->setTenant($tenantId)
            ->where('wa_number', $contact['wa_number'])->first();
        $windowOpen = $conv
            ? (new WindowService(new ConversationModel()))->isOpenForConversation($conv)
            : false;

        return $this->respond(['success' => true, 'data' => [
            'window_open'        => $windowOpen,
            'conversation_id'    => $conv['id'] ?? null,
            'window_expires_at'  => $conv['window_expires_at'] ?? null,
        ]]);
    }

    // POST /api/v1/contacts/:id/send-template — send an approved template to a contact
    // Body: { template_id, variables?: ["v1","v2",...] }
    public function sendTemplate($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $contact  = (new ContactModel())->setTenant($tenantId)->find((int) $id);
        if (! $contact) {
            return $this->failNotFound("Contact #{$id} not found.");
        }

        $templateId = (int) $this->request->getJsonVar('template_id');
        $template   = (new TemplateModel())->setTenant($tenantId)->find($templateId);
        if (! $template) {
            return $this->fail(['template_id' => 'Template not found.'], 422);
        }
        if (($template['meta_status'] ?? '') !== 'approved') {
            return $this->fail(['template_id' => "Template is not approved (status: {$template['meta_status']})."], 422);
        }
        if (($template['category'] ?? '') === 'marketing' && ! (bool) ($contact['opt_in'] ?? 1)) {
            return $this->fail(['opt_in' => 'This contact has not opted in for marketing messages.'], 422);
        }

        // Build body params for {{1}}..{{N}} from the supplied values; default {{1}} to the name.
        $tplVars = json_decode($template['variables'] ?? '[]', true) ?: [];
        $n       = is_array($tplVars) ? count($tplVars) : 0;
        $vars    = $this->request->getJsonVar('variables') ?? [];
        $vars    = is_array($vars) ? array_values($vars) : [];

        $bodyParams = [];
        for ($i = 1; $i <= $n; $i++) {
            $val = trim((string) ($vars[$i - 1] ?? ''));
            if ($val === '' && $i === 1) {
                $val = $contact['name'] ?: 'there';
            }
            if ($val === '') {
                $label = $tplVars[$i - 1] ?? "variable {$i}";
                return $this->fail(['variables' => "Fill in {{{$i}}} ({$label}) — WhatsApp rejects templates with empty variables."], 422);
            }
            $bodyParams[] = ['type' => 'text', 'text' => $val];
        }

        $account = (new WabaAccountModel())->findActive($tenantId);
        if (! $account) {
            return $this->fail(['waba' => 'No active WhatsApp account connected.'], 422);
        }
        $client = (new WabaAccountModel())->buildAdapter(is_array($account) ? $account : (array) $account);

        $components = TemplateComponentBuilder::forSend($template, $bodyParams);
        $result     = $client->sendTemplate($contact['wa_number'], $template['name'], $template['language'], $components);
        $status     = ($result['success'] ?? false) ? 'sent' : 'failed';

        // Record the message against a conversation.
        $conv       = (new ConversationModel())->findOrCreate($tenantId, $contact['wa_number']);
        $convId     = (int) $conv['id'];
        $windowOpen = (new WindowService(new ConversationModel()))->isOpenForConversation($conv);

        (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => (int) $contact['id'],
            'conversation_id' => $convId,
            'direction'       => 'out',
            'type'            => 'template',
            'template_id'     => (int) ($template['id'] ?? 0) ?: null,
            'category'        => $template['category'] ?? 'utility',
            'body'            => $template['body'] ?? '',
            'wa_message_id'   => $result['message_id'] ?? null,
            'status'          => $status,
            // Only a send Meta accepted is billable.
            'billable'        => ($result['success'] ?? false)
                ? BillableComputer::compute($template['category'] ?? 'utility', $windowOpen) : 0,
            'error'           => $result['error'] ?? null,
            'sent_at'         => ($result['success'] ?? false) ? date('Y-m-d H:i:s') : null,
        ]);

        if (! ($result['success'] ?? false)) {
            return $this->fail(['send' => $result['error'] ?? 'Send failed.'], 422);
        }
        return $this->respond(['success' => true, 'message' => 'Template sent.', 'conversation_id' => $convId]);
    }

    // POST /api/v1/contacts/:id/tags/:tag_id
    public function attachTag(int $id, int $tagId): ResponseInterface
    {
        $model = (new ContactModel())->setTenant(CurrentUser::tenantId());
        if (! $model->find($id)) {
            return $this->failNotFound("Contact #{$id} not found.");
        }
        $model->attachTag($id, $tagId);
        return $this->respond(['success' => true, 'tags' => $model->getTagsFor($id)]);
    }

    // DELETE /api/v1/contacts/:id/tags/:tag_id
    public function detachTag(int $id, int $tagId): ResponseInterface
    {
        $model = (new ContactModel())->setTenant(CurrentUser::tenantId());
        if (! $model->find($id)) {
            return $this->failNotFound("Contact #{$id} not found.");
        }
        $model->detachTag($id, $tagId);
        return $this->respond(['success' => true, 'tags' => $model->getTagsFor($id)]);
    }
}
