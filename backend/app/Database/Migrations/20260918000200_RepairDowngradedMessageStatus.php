<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Repair message rows whose status contradicts their own timestamps.
 *
 * WhatsApp's status callbacks are not ordered, and until the ladder guard in
 * WebhookService a retried `delivered` arriving after `read` overwrote the
 * status while leaving read_at in place. The result is rows that say
 * "delivered" and carry the exact moment the recipient opened the message.
 *
 * That is not only confusing in the log — every read rate in the product is a
 * GROUP BY on `status`, so each downgraded row silently deflates the one number
 * the delivery report exists to answer.
 *
 * The repair only ever promotes a row to a rung it can prove it reached, from
 * the timestamp WhatsApp itself sent. `failed` rows are left alone: their
 * billable flag was zeroed when the failure landed, and this migration has no
 * way to decide what the charge should have been.
 */
class RepairDowngradedMessageStatus extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;

        $this->db->query(
            "UPDATE {$p}messages SET status = 'read'
              WHERE read_at IS NOT NULL
                AND status IN ('queued', 'sent', 'delivered')"
        );
        // Read the count immediately — affectedRows() reports the LAST query.
        $read = $this->db->affectedRows();

        $this->db->query(
            "UPDATE {$p}messages SET status = 'delivered'
              WHERE delivered_at IS NOT NULL
                AND status IN ('queued', 'sent')"
        );
        $delivered = $this->db->affectedRows();

        log_message('info', sprintf(
            'RepairDowngradedMessageStatus: promoted %d to read, %d to delivered.',
            $read,
            $delivered
        ));
    }

    public function down(): void
    {
        // Irreversible by design: the pre-repair status was the wrong one, and
        // nothing records which rows were touched.
    }
}
