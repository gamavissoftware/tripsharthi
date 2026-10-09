<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Facebook Login is no longer only the Social Planner's: the Integrations page
 * starts the same flow to connect a Page for Meta Lead Ads. Meta's redirect
 * lands on one backend callback, which must know which SPA page to bounce the
 * browser back to — that is what this column records at start() time.
 */
class AddReturnToToSocialOauthStates extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('social_oauth_states', [
            'return_to' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'social',
                'after'      => 'status',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('social_oauth_states', 'return_to');
    }
}
