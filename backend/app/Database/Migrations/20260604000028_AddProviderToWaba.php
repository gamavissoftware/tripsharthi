<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds multi-provider support to waba_accounts.
 *
 * provider             — which WhatsApp provider (meta|wati|aisensy|360dialog|twilio|custom)
 * provider_config_json — provider-specific config (endpoint, API keys, etc.)
 *
 * Meta accounts continue to use the existing waba_id / access_token_enc columns.
 * Other providers store all connection details in provider_config_json.
 */
class AddProviderToWaba extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('waba_accounts', [
            'provider' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
                'default'    => 'meta',
                'after'      => 'status',
            ],
            'provider_config_json' => [
                'type'    => 'TEXT',
                'null'    => true,
                'default' => null,
                'after'   => 'provider',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('waba_accounts', ['provider', 'provider_config_json']);
    }
}
