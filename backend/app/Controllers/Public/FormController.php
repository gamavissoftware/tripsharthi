<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\WebFormModel;
use App\Services\Leads\FormHandler;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public (no auth) hosted web form.
 *
 * GET  /forms/{token}        — render HTML form
 * POST /forms/{token}/submit — handle submission (HTML or JSON)
 */
class FormController extends Controller
{
    // GET /forms/{token}
    public function show(string $token): ResponseInterface
    {
        $form = (new WebFormModel())->findByToken($token);

        if ($form === null || ! (bool) $form['active']) {
            return $this->response->setStatusCode(404)->setBody(
                view('forms/not_found')
            );
        }

        $fields = WebFormModel::normalizeFields($form['fields'] ?? null);
        return $this->response->setBody(
            view('forms/show', ['form' => $form, 'fields' => $fields, 'errors' => [], 'old' => []])
        );
    }

    // POST /forms/{token}/submit
    public function submit(string $token): ResponseInterface
    {
        $isJson  = str_contains($this->request->getHeaderLine('Accept'), 'application/json')
                || str_contains($this->request->getHeaderLine('Content-Type'), 'application/json');
        $postData = $isJson
            ? ($this->request->getJSON(true) ?? [])
            : $this->request->getPost();

        $handler = new FormHandler(
            new WebFormModel(),
            new ContactModel(),
            new ContactFieldValueModel()
        );

        $result = $handler->handle($token, $postData);

        if ($isJson) {
            $code = $result['ok'] ? 200 : ($result['errors']['form'] ?? false ? 404 : 422);
            return $this->response
                ->setStatusCode($code)
                ->setContentType('application/json')
                ->setBody(json_encode($result));
        }

        // HTML form flow
        if (! $result['ok']) {
            $form   = (new WebFormModel())->findByToken($token);
            $fields = $form ? (json_decode($form['fields'] ?? 'null', true) ?? WebFormModel::defaultFields()) : [];
            return $this->response->setBody(
                view('forms/show', [
                    'form'   => $form ?? [],
                    'fields' => $fields,
                    'errors' => $result['errors'],
                    'old'    => $postData,
                ])
            );
        }

        // Success: redirect or show thank-you
        if (! empty($result['redirect_url'])) {
            return redirect()->to($result['redirect_url']);
        }
        return $this->response->setBody(view('forms/submitted'));
    }
}
