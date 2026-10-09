<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Meta App ID on the WABA account.
 *
 * Required for the resumable-upload API (POST /{app_id}/uploads) that turns a
 * card image into the `header_handle` a carousel/media template needs at
 * submission time.
 */
class AddAppIdToWabaAccounts extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('waba_accounts', [
            'app_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'business_id',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('waba_accounts', 'app_id');
    }
}
