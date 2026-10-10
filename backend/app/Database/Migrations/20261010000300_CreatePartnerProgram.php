<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Referral partner program (platform level; partners are external people, not workspace users).
 *  - partners: one login per partner (email + password set through a one-time invite link). `code` is the public referral code.
 *    commission_pct / commission_months (0 = lifetime) / hold_days are per partner. payout_enc = encrypted JSON (UPI or bank + PAN).
 *  - partner_sessions: like admin_sessions (only the SHA-256 of the token is stored).
 *  - partner_commissions: one row per verified paid subscription payment of a referred customer (UNIQUE subscription_id => never twice).
 *    status pending|paid|void; "available" = pending AND payable_after has passed (computed, not stored).
 *  - partner_payouts: money actually sent to a partner; it settles every available commission at that moment.
 *  - partner_events: security/audit trail of partner accounts (logins, password and payout-detail changes, payouts) - the workspace audit log cannot hold these.
 *  - tenants.referred_partner_id/referred_at/referral_source: who referred a workspace (first touch wins, never overwritten).
 */
class CreatePartnerProgram extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 120],
            'company'           => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true, 'default' => null],
            'email'             => ['type' => 'VARCHAR', 'constraint' => 190],
            'phone'             => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'code'              => ['type' => 'VARCHAR', 'constraint' => 20],
            'status'            => ['type' => 'ENUM', 'constraint' => ['invited', 'active', 'suspended'], 'default' => 'invited'],
            'commission_pct'    => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => '20.00'],
            'commission_months' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 12],
            'hold_days'         => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 30],
            'payout_enc'        => ['type' => 'TEXT', 'null' => true],
            'notes'             => ['type' => 'TEXT', 'null' => true],
            'password_hash'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'invite_token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'invite_expires_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'last_login_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_by'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('email');
        $this->forge->addUniqueKey('code');
        $this->forge->addKey('invite_token_hash');
        $this->forge->createTable('partners');

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'partner_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'token_hash'   => ['type' => 'CHAR', 'constraint' => 64],
            'ip'           => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'default' => null],
            'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'default' => null],
            'created_at'   => ['type' => 'DATETIME'],
            'last_seen_at' => ['type' => 'DATETIME'],
            'expires_at'   => ['type' => 'DATETIME'],
            'revoked_at'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey(['partner_id', 'revoked_at']);
        $this->forge->addForeignKey('partner_id', 'partners', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('partner_sessions');

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'partner_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'subscription_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'plan'            => ['type' => 'VARCHAR', 'constraint' => 20],
            'cycle'           => ['type' => 'VARCHAR', 'constraint' => 10],
            'base_paise'      => ['type' => 'BIGINT', 'unsigned' => true],                       // what the customer actually paid
            'rate_pct'        => ['type' => 'DECIMAL', 'constraint' => '5,2'],
            'amount_paise'    => ['type' => 'BIGINT', 'unsigned' => true],
            'status'          => ['type' => 'ENUM', 'constraint' => ['pending', 'paid', 'void'], 'default' => 'pending'],
            'earned_at'       => ['type' => 'DATETIME'],
            'payable_after'   => ['type' => 'DATETIME'],
            'payout_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'void_reason'     => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true, 'default' => null],
            'voided_at'       => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'      => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('subscription_id');
        $this->forge->addKey(['partner_id', 'status', 'payable_after']);
        $this->forge->addKey(['partner_id', 'tenant_id']);
        $this->forge->addForeignKey('partner_id', 'partners', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('partner_commissions');

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'partner_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'amount_paise' => ['type' => 'BIGINT', 'unsigned' => true],
            'method'       => ['type' => 'VARCHAR', 'constraint' => 20],
            'reference'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'note'         => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true, 'default' => null],
            'commissions'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'paid_at'      => ['type' => 'DATETIME'],
            'created_by'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'   => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('partner_id');
        $this->forge->addForeignKey('partner_id', 'partners', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('partner_payouts');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'partner_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'actor'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'partner'],          // partner | admin | system
            'actor_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'action'     => ['type' => 'VARCHAR', 'constraint' => 60],
            'detail'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['partner_id', 'id']);
        $this->forge->addForeignKey('partner_id', 'partners', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('partner_events');

        $this->forge->addColumn('tenants', [
            'referred_partner_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'referred_at'         => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'referral_source'     => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'default' => null],
        ]);
        $this->db->query('ALTER TABLE tenants ADD INDEX idx_tenants_referred_partner (referred_partner_id)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE tenants DROP INDEX idx_tenants_referred_partner');
        $this->forge->dropColumn('tenants', ['referred_partner_id', 'referred_at', 'referral_source']);
        foreach (['partner_events', 'partner_payouts', 'partner_commissions', 'partner_sessions', 'partners'] as $t) { $this->forge->dropTable($t, true); }
    }
}
