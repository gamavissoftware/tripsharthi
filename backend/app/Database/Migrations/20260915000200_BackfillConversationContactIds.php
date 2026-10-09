<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Repair: link existing conversations to their CRM contact.
 *
 * ConversationModel::findOrCreate() only stored a contact_id when the caller
 * supplied one, and almost none did — the inbound webhook, campaign sends and
 * the flow engine all pass just a number. Conversations were therefore created
 * with contact_id NULL even when the contact plainly existed, so the inbox
 * could not show who it was talking to.
 *
 * findOrCreate() now resolves the contact itself; this fixes the rows already
 * written. Matching strips the '+' on both sides because the two spellings
 * ("+9198…" from webhooks, "9198…" from some import paths) both occur.
 */
class BackfillConversationContactIds extends Migration
{
    public function up(): void
    {
        $this->db->query(
            'UPDATE conversations c
             JOIN contacts ct
               ON ct.tenant_id = c.tenant_id
              AND ct.deleted_at IS NULL
              AND TRIM(LEADING "+" FROM ct.wa_number) = TRIM(LEADING "+" FROM c.wa_number)
             SET c.contact_id = ct.id
             WHERE c.contact_id IS NULL'
        );
    }

    public function down(): void
    {
        // Not reversible: which conversations were NULL beforehand is not
        // recorded, and blanking every contact_id would destroy links that
        // were set legitimately. Leaving the repair in place is the safe
        // direction — a wrong link can be corrected, a lost one cannot.
    }
}
