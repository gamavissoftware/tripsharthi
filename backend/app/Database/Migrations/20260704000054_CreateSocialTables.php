<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Social Planner — schedule Facebook Page / Instagram Business posts.
 *
 * social_accounts: one row per connected Facebook Page. The page access token
 * is stored encrypted (TokenCipher, same as WABA tokens). If the page has a
 * linked Instagram Business account it is discovered at connect time and
 * stored on the same row (ig_user_id/ig_username) — Instagram publishing
 * always goes through the page token.
 *
 * social_posts: one row per platform per scheduled post. The dispatcher
 * (spark social:dispatch) claims due rows by flipping status
 * scheduled → publishing (idempotent claim), then publishes via the Graph API
 * and records platform_post_id or the error.
 */
class CreateSocialTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'page_id'     => ['type' => 'VARCHAR', 'constraint' => 50],
            'page_name'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            // Linked Instagram Business account (discovered from the page), if any
            'ig_user_id'  => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'ig_username' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            // AES-256-GCM ciphertext (TokenCipher) — never stored raw
            'access_token_enc' => ['type' => 'TEXT'],
            'status'      => ['type' => 'ENUM', 'constraint' => ['active', 'error'], 'default' => 'active'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'page_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('social_accounts');

        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'social_account_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'platform'          => ['type' => 'ENUM', 'constraint' => ['facebook', 'instagram']],
            'message'           => ['type' => 'TEXT'],
            // v1 media = a public image URL (Instagram requires one)
            'image_url'         => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'scheduled_at'      => ['type' => 'DATETIME'],
            'status'            => [
                'type'       => 'ENUM',
                'constraint' => ['scheduled', 'publishing', 'published', 'failed'],
                'default'    => 'scheduled',
            ],
            'platform_post_id'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'error'             => ['type' => 'TEXT', 'null' => true],
            'published_at'      => ['type' => 'DATETIME', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // The dispatcher's scan: WHERE status='scheduled' AND scheduled_at <= now
        $this->forge->addKey(['status', 'scheduled_at']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id',         'tenants',         'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('social_account_id', 'social_accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('social_posts');
    }

    public function down(): void
    {
        $this->forge->dropTable('social_posts', true);
        $this->forge->dropTable('social_accounts', true);
    }
}
