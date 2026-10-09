<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\IntegrationModel;
use App\Services\Auth\CurrentUser;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * IMAP mailbox integration for inbound-email polling (Phase M).
 *
 * GET    /api/v1/imap-integrations      — current config (password never returned)
 * POST   /api/v1/imap-integrations      — connect/update (password encrypted at rest)
 * DELETE /api/v1/imap-integrations/:id  — disconnect
 *
 * The `email:poll-imap` cron reads these rows, decrypts the password, and polls.
 */
class ImapIntegrationsController extends ResourceController
{
    protected $format = 'json';

    private function model(): IntegrationModel
    {
        return (new IntegrationModel())->setTenant(CurrentUser::tenantId());
    }

    public function index(): ResponseInterface
    {
        $row = $this->model()->where('type', 'email_imap')->first();
        if (! $row) {
            return $this->respond(['success' => true, 'data' => null]);
        }
        $config = json_decode($row['config'] ?? '{}', true) ?: [];
        // Never expose the stored password; signal whether one is set.
        $hasPassword = ! empty($config['password_enc']);
        unset($config['password_enc'], $config['password']);
        $config['has_password'] = $hasPassword;

        return $this->respond(['success' => true, 'data' => [
            'id' => (int) $row['id'], 'status' => $row['status'], 'config' => $config,
        ]]);
    }

    public function create(): ResponseInterface
    {
        $host     = trim((string) $this->request->getJsonVar('host'));
        $username = trim((string) $this->request->getJsonVar('username'));
        $password = (string) ($this->request->getJsonVar('password') ?? '');
        $port     = (int) ($this->request->getJsonVar('port') ?: 993);
        $folder   = trim((string) ($this->request->getJsonVar('folder') ?: 'INBOX'));
        $ssl      = $this->request->getJsonVar('ssl');
        $ssl      = $ssl === null ? true : (bool) $ssl;

        if ($host === '' || $username === '') {
            return $this->fail(['host' => 'host and username are required.'], 422);
        }

        $tenantId = CurrentUser::tenantId();
        $existing = $this->model()->where('type', 'email_imap')->first();
        $config   = $existing ? (json_decode($existing['config'] ?? '{}', true) ?: []) : [];

        $config = array_merge($config, compact('host', 'port', 'username', 'folder', 'ssl'));
        // Keep the existing password if the caller didn't send a new one (edit without re-typing).
        if ($password !== '') {
            $config['password_enc'] = TokenCipher::encrypt($password);
        }
        if (empty($config['password_enc'])) {
            return $this->fail(['password' => 'A mailbox password is required.'], 422);
        }

        $id  = $this->model()->saveConfig($tenantId, 'email_imap', ['config' => json_encode($config)]);
        $cfg = $config;
        unset($cfg['password_enc']);
        $cfg['has_password'] = true;

        return $this->respondCreated(['success' => true, 'data' => ['id' => (int) $id, 'config' => $cfg]]);
    }

    public function delete($id = null): ResponseInterface
    {
        $row = $this->model()->find((int) $id);
        if (! $row || $row['type'] !== 'email_imap') {
            return $this->failNotFound("Integration #{$id} not found.");
        }
        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }
}
