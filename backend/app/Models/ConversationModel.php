<?php

declare(strict_types=1);

namespace App\Models;

class ConversationModel extends BaseModel
{
    protected $table      = 'conversations';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id', 'contact_id', 'phone_number_id', 'wa_number',
        'contact_name', 'window_expires_at', 'last_message_at',
        'last_inbound_at', 'unread_count', 'is_read', 'status', 'assigned_user_id',
    ];

    /**
     * Find (or create) the conversation for a given wa_number within a tenant.
     * Returns the conversation row (existing or newly inserted).
     */
    public function findOrCreate(int $tenantId, string $waNumber, array $defaults = []): array
    {
        // Match an existing conversation regardless of '+'-prefix drift. Inbound
        // webhooks normalise to "+91…" while some outbound/import paths stored the
        // bare "91…" — without this, the same person got two separate threads.
        $digits    = ltrim($waNumber, '+');
        $canonical = ($digits !== '' && ctype_digit($digits)) ? '+' . $digits : $waNumber;

        foreach (array_unique([$canonical, $digits, $waNumber]) as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $existing = $this->setTenant($tenantId)->where('wa_number', $candidate)->first();
            if ($existing !== null) {
                return is_array($existing) ? $existing : (array) $existing;
            }
        }

        // Link the CRM contact unless the caller already named one. Almost no
        // caller does — the inbound webhook, campaign sends and the flow engine
        // all pass only a number — which left contact_id NULL on nearly every
        // conversation, so the inbox could not show who the person actually is.
        // Resolving it here fixes every call site at once.
        if (! array_key_exists('contact_id', $defaults)) {
            $contactId = $this->resolveContactId($tenantId, $canonical, $digits);
            if ($contactId !== null) {
                $defaults['contact_id'] = $contactId;
            }
        }

        $id = (int) $this->withoutTenantScope()->insert(array_merge([
            'tenant_id'   => $tenantId,
            'wa_number'   => $canonical, // always store canonical "+E.164"
            'status'      => 'open',
            'unread_count'=> 0,
        ], $defaults), true);

        return (array) $this->withoutTenantScope()->find($id);
    }

    /**
     * The contact id for a number, matching both stored spellings ("+91…" and
     * bare "91…") the way findOrCreate matches conversations.
     */
    private function resolveContactId(int $tenantId, string $canonical, string $digits): ?int
    {
        $variants = array_values(array_unique(array_filter([$canonical, $digits])));
        if ($variants === []) {
            return null;
        }

        // Non-fatal: the link is an enrichment, and this runs on the inbound
        // webhook path. A conversation that cannot resolve its contact is worth
        // far less than an inbound message dropped because the lookup failed.
        try {
            $row = $this->db->table('contacts')
                ->select('id')
                ->where('tenant_id', $tenantId)
                ->whereIn('wa_number', $variants)
                ->where('deleted_at', null)
                ->orderBy('id', 'ASC')
                ->get(1)
                ->getRowArray();
        } catch (\Throwable $e) {
            log_message('error', '[Conversation] contact link lookup failed: ' . $e->getMessage());
            return null;
        }

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Select list + joins that decorate a conversation with its agent, and the
     * contact's company and category.
     *
     * Every join is LEFT: a conversation can precede its contact row (an
     * inbound from an unknown number), a contact need not belong to a company,
     * and neither absence may drop the conversation out of the inbox.
     */
    private function selectWithCompany(): static
    {
        return $this->select(
            'conversations.*, u.name AS assigned_agent_name,'
            . ' cnt.business_type AS contact_category,'
            . ' cnt.email AS contact_email,'
            . ' acc.name AS company_name, acc.industry AS company_industry'
        )
            ->join('users u', 'u.id = conversations.assigned_user_id', 'left')
            ->join('contacts cnt', 'cnt.id = conversations.contact_id AND cnt.deleted_at IS NULL', 'left')
            ->join('accounts acc', 'acc.id = cnt.account_id AND acc.deleted_at IS NULL', 'left');
    }

    /**
     * One conversation with the same company/category decoration as the list,
     * so opening a thread does not blank the header those fields populate.
     */
    public function findForInbox(int $tenantId, int $id): ?array
    {
        $this->setTenant($tenantId);
        $row = $this->selectWithCompany()->where('conversations.id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Paginated conversation list for the inbox.
     */
    public function listForInbox(int $tenantId, array $filters = [], int $perPage = 25): array
    {
        // The ORDER BY is raw (unescaped) so COALESCE survives, which means the
        // table name must be prefixed by hand — CI4 only rewrites names it
        // escapes, so a hardcoded "conversations." breaks under a DBPrefix.
        $tbl = $this->db->prefixTable($this->table);

        $this->setTenant($tenantId);
        $this->selectWithCompany()
             ->orderBy("COALESCE({$tbl}.last_message_at, {$tbl}.created_at)", 'DESC', false);

        if (! empty($filters['status'])) {
            $this->where('conversations.status', $filters['status']);
        }
        if (! empty($filters['unread_only'])) {
            $this->where('conversations.unread_count >', 0);
        }
        if (! empty($filters['assigned_user_id'])) {
            $this->where('conversations.assigned_user_id', (int) $filters['assigned_user_id']);
        }
        if (! empty($filters['q'])) {
            $this->groupStart()
                ->like('conversations.wa_number', $filters['q'])
                ->orLike('conversations.contact_name', $filters['q'])
                ->groupEnd();
        }
        if (! empty($filters['category'])) {
            $this->where('cnt.business_type', $filters['category']);
        }

        return $this->paginate($perPage);
    }
}
