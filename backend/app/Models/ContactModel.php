<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Leads\WaNumberNormalizer;

class ContactModel extends BaseModel
{
    protected $table      = 'contacts';
    protected $primaryKey = 'id';
    protected bool $ownable    = true;
    protected string $entityType = 'contact';

    protected $allowedFields = [
        'tenant_id', 'wa_number', 'name', 'email', 'language',
        'status', 'source', 'opt_in', 'last_inbound_at',
        // CRM fields (Phase A)
        'account_id', 'owner_id', 'job_title', 'lifecycle_stage', 'lead_score', 'phone_secondary',
        // Lead scoring (Phase H1)
        'score_tier', 'score_breakdown',
        // Lead qualification (CRM richness)
        'city', 'state', 'country', 'business_type', 'requirement_type', 'current_process',
        'budget_amount', 'timeline', 'qualification_status', 'ai_call_status', 'priority', 'remarks',
    ];

    protected $validationRules = [
        'wa_number'       => 'permit_empty|max_length[20]',
        'status'          => 'in_list[new,contacted,qualified,won,lost]',
        'source'          => 'in_list[manual,csv_import,web_form,meta_lead_ads,google_lead_forms,whatsapp_inbound,email_inbound,shopify,woocommerce,portal]',
        'lifecycle_stage' => 'permit_empty|in_list[subscriber,lead,mql,sql,opportunity,customer,evangelist,other]',
        'priority'        => 'permit_empty|in_list[low,medium,high]',
    ];

    /**
     * Find a contact by normalised wa_number within the current tenant.
     */
    public function findByWaNumber(string $waNumber): array|object|null
    {
        $this->scopeTenant();

        // Match every spelling of the number ('+9198…' and '9198…'). An exact
        // match alone lets an imported lead and their inbound WhatsApp reply
        // become two separate contacts — see WaNumberNormalizer::variants().
        $variants = WaNumberNormalizer::variants($waNumber);
        if ($variants === []) {
            return null;
        }

        return $this->whereIn('wa_number', $variants)->first();
    }

    /**
     * Find a contact by email within the current tenant — the dedupe key for
     * email-only contacts (those with no wa_number). Case-insensitive.
     */
    public function findByEmail(string $email): array|object|null
    {
        $this->scopeTenant();
        return $this->where('LOWER(email)', strtolower($email))->first();
    }

    /**
     * Return contacts with their tag names for a list view.
     *
     * @return array<int, array>
     */
    public function listWithTags(array $filters = [], int $perPage = 25): array
    {
        $this->scopeTenant();

        if (! empty($filters['status'])) {
            $this->where('contacts.status', $filters['status']);
        }
        if (! empty($filters['source'])) {
            $this->where('contacts.source', $filters['source']);
        }
        if (! empty($filters['q'])) {
            $this->groupStart()
                ->like('contacts.name', $filters['q'])
                ->orLike('contacts.wa_number', $filters['q'])
                ->orLike('contacts.email', $filters['q'])
                ->groupEnd();
        }
        if (! empty($filters['tag_id'])) {
            $this->join('contact_tags ct', 'ct.contact_id = contacts.id')
                 ->where('ct.tag_id', (int) $filters['tag_id']);
        }
        if (! empty($filters['category'])) {
            $this->where('contacts.business_type', $filters['category']);
        }

        // LEFT JOIN, not INNER: contacts with no company must still be listed.
        // One account per contact, so this cannot multiply rows or skew the
        // pagination count.
        $rows = $this->select('contacts.*, accounts.name AS company_name, accounts.industry AS company_industry')
                     ->join('accounts', 'accounts.id = contacts.account_id AND accounts.deleted_at IS NULL', 'left')
                     ->orderBy('contacts.created_at', 'DESC')
                     ->paginate($perPage);

        return $this->attachTags($rows);
    }

    /**
     * The company (account) a contact belongs to, or null when unlinked.
     *
     * Kept separate from find() so the detail endpoint can decorate a contact
     * without every other caller paying for the join.
     *
     * @return array{id:int, name:string, industry:?string}|null
     */
    public function companyFor(int $tenantId, ?int $accountId): ?array
    {
        if (! $accountId) {
            return null;
        }

        $row = $this->db->table('accounts')
            ->select('id, name, industry')
            ->where('id', $accountId)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $row === null ? null : [
            'id'       => (int) $row['id'],
            'name'     => (string) $row['name'],
            'industry' => $row['industry'] !== null ? (string) $row['industry'] : null,
        ];
    }

    /**
     * Distinct company categories in use by this tenant, for the list filter.
     *
     * Read from contacts.business_type rather than accounts.industry so that
     * contacts carrying a category but no company row still appear as options.
     *
     * @return list<string>
     */
    public function distinctCategories(int $tenantId): array
    {
        $rows = $this->db->table('contacts')
            ->distinct()
            ->select('business_type')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->where('business_type IS NOT NULL')
            ->where('business_type !=', '')
            ->orderBy('business_type', 'ASC')
            ->get()
            ->getResultArray();

        return array_values(array_map(static fn ($r) => (string) $r['business_type'], $rows));
    }

    /**
     * Attach each contact's tags as a `tags` array of {id, name, color}.
     *
     * Done as one extra query over the page of rows rather than a join, so
     * pagination counts stay correct when a contact carries several tags.
     *
     * @param  array<int, array> $rows
     * @return array<int, array>
     */
    public function attachTags(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);

        $links = $this->db->table('contact_tags ct')
            ->select('ct.contact_id, t.id, t.name, t.color')
            ->join('tags t', 't.id = ct.tag_id AND t.deleted_at IS NULL', 'inner')
            ->whereIn('ct.contact_id', $ids)
            ->get()
            ->getResultArray();

        $byContact = [];
        foreach ($links as $l) {
            $byContact[(int) $l['contact_id']][] = [
                'id'    => (int) $l['id'],
                'name'  => $l['name'],
                'color' => $l['color'],
            ];
        }

        foreach ($rows as &$row) {
            $row['tags'] = $byContact[(int) $row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * Return all contacts matching the list filters, each with a comma-joined
     * `tags` string, for CSV export. No pagination — capped at $cap rows.
     *
     * @return array<int, array>
     */
    public function exportRows(array $filters = [], int $cap = 50000): array
    {
        $this->scopeTenant();

        $this->select('contacts.*, GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR \', \') AS tags')
             ->join('contact_tags ctx', 'ctx.contact_id = contacts.id', 'left')
             ->join('tags t', 't.id = ctx.tag_id AND t.deleted_at IS NULL', 'left');

        if (! empty($filters['status'])) {
            $this->where('contacts.status', $filters['status']);
        }
        if (! empty($filters['source'])) {
            $this->where('contacts.source', $filters['source']);
        }
        if (! empty($filters['q'])) {
            $this->groupStart()
                ->like('contacts.name', $filters['q'])
                ->orLike('contacts.wa_number', $filters['q'])
                ->orLike('contacts.email', $filters['q'])
                ->groupEnd();
        }
        if (! empty($filters['tag_id'])) {
            // Restrict to contacts carrying this tag without disturbing the
            // GROUP_CONCAT join (which still emits ALL of the contact's tags).
            $this->whereIn('contacts.id', static function ($builder) use ($filters) {
                return $builder->select('contact_id')
                    ->from('contact_tags')
                    ->where('tag_id', (int) $filters['tag_id']);
            });
        }
        if (! empty($filters['category'])) {
            $this->where('contacts.business_type', $filters['category']);
        }

        return $this->groupBy('contacts.id')
                    ->orderBy('contacts.created_at', 'DESC')
                    ->findAll($cap);
    }

    /**
     * Attach a tag to a contact (idempotent).
     */
    public function attachTag(int $contactId, int $tagId): void
    {
        $existing = $this->db->table('contact_tags')
            ->where('contact_id', $contactId)
            ->where('tag_id', $tagId)
            ->get()->getRow();

        if ($existing === null) {
            $this->db->table('contact_tags')->insert([
                'contact_id' => $contactId,
                'tag_id'     => $tagId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Detach a tag from a contact.
     */
    public function detachTag(int $contactId, int $tagId): void
    {
        $this->db->table('contact_tags')
            ->where('contact_id', $contactId)
            ->where('tag_id', $tagId)
            ->delete();
    }

    /**
     * Return tag rows for a given contact.
     */
    public function getTagsFor(int $contactId): array
    {
        return $this->db->table('tags t')
            ->join('contact_tags ct', 'ct.tag_id = t.id')
            ->where('ct.contact_id', $contactId)
            ->where('t.deleted_at', null)   // CI4 builder renders this as IS NULL (no whereNull())
            ->select('t.*')
            ->get()->getResultArray();
    }
}
