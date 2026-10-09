<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\WebFormModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

class WebFormsController extends ResourceController
{
    protected $format = 'json';

    public function index(): ResponseInterface
    {
        $model = (new WebFormModel())->setTenant(CurrentUser::tenantId());
        $forms = $model->findAll();

        // show() decodes fields; the list must too, or the client receives a raw
        // JSON string and renders the form as having no fields at all.
        foreach ($forms as &$form) {
            $form['fields'] = json_decode($form['fields'] ?? 'null', true)
                ?? WebFormModel::defaultFields();
        }
        unset($form);

        return $this->respond(['success' => true, 'data' => $forms]);
    }

    public function show($id = null): ResponseInterface
    {
        $form = (new WebFormModel())->setTenant(CurrentUser::tenantId())->find((int) $id);
        if (! $form) {
            return $this->failNotFound("Form #{$id} not found.");
        }
        $form['fields'] = json_decode($form['fields'] ?? 'null', true) ?? WebFormModel::defaultFields();
        return $this->respond(['success' => true, 'data' => $form]);
    }

    public function create(): ResponseInterface
    {
        $rules = ['title' => 'required|max_length[255]'];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $fields      = self::normalizeFields($this->request->getJsonVar('fields', true));
        $model       = (new WebFormModel())->setTenant(CurrentUser::tenantId());
        $id          = $model->insert([
            'title'        => $this->request->getJsonVar('title'),
            'fields'       => json_encode($fields),
            'redirect_url' => $this->request->getJsonVar('redirect_url'),
            'active'       => 1,
            'form_token'   => bin2hex(random_bytes(16)), // 32-char hex token
        ], true);

        $form           = $model->find((int) $id);
        $form['fields'] = json_decode($form['fields'], true);
        return $this->respondCreated(['success' => true, 'data' => $form]);
    }

    public function update($id = null): ResponseInterface
    {
        $model = (new WebFormModel())->setTenant(CurrentUser::tenantId());
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Form #{$id} not found.");
        }

        $rawFields = $this->request->getJsonVar('fields', true);
        $payload = array_filter([
            'title'        => $this->request->getJsonVar('title'),
            'fields'       => $rawFields ? json_encode(self::normalizeFields($rawFields)) : null,
            'redirect_url' => $this->request->getJsonVar('redirect_url'),
            'active'       => $this->request->getJsonVar('active'),
        ], static fn ($v) => $v !== null);

        $model->setTenant(CurrentUser::tenantId())->update((int) $id, $payload);
        return $this->respond(['success' => true, 'data' => $model->find((int) $id)]);
    }

    /**
     * Coerce a fields payload into the canonical [{field_key,label,required}] shape
     * the public form view + FormHandler consume. Accepts legacy string arrays
     * (['name','email']) for backward compatibility, and guarantees wa_number is
     * present and required.
     */
    public static function normalizeFields(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return WebFormModel::defaultFields();
        }
        $out  = [];
        $seen = [];
        foreach ($raw as $f) {
            if (is_string($f)) {
                $key = trim($f);
                $def = ['field_key' => $key, 'label' => ucwords(str_replace('_', ' ', $key)), 'required' => $key === 'wa_number'];
            } elseif (is_array($f) && ! empty($f['field_key'])) {
                $key = (string) $f['field_key'];
                $def = [
                    'field_key' => $key,
                    'label'     => trim((string) ($f['label'] ?? '')) ?: ucwords(str_replace('_', ' ', $key)),
                    'required'  => ! empty($f['required']) || $key === 'wa_number',
                ];
            } else {
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $def;
        }
        // wa_number is mandatory on every form.
        if (! isset($seen['wa_number'])) {
            array_unshift($out, ['field_key' => 'wa_number', 'label' => 'WhatsApp Number', 'required' => true]);
        }
        return $out;
    }

    public function delete($id = null): ResponseInterface
    {
        $model = (new WebFormModel())->setTenant(CurrentUser::tenantId());
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Form #{$id} not found.");
        }
        $model->setTenant(CurrentUser::tenantId())->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    /** Return the embed snippet for a form. */
    public function snippet($id = null): ResponseInterface
    {
        $form = (new WebFormModel())->setTenant(CurrentUser::tenantId())->find((int) $id);
        if (! $form) {
            return $this->failNotFound("Form #{$id} not found.");
        }
        $token = $form['form_token'];
        $base  = base_url();
        $snippet = "<script src=\"{$base}embed/{$token}.js\"></script>";

        return $this->respond(['success' => true, 'snippet' => $snippet, 'form_url' => base_url("forms/{$token}")]);
    }
}
