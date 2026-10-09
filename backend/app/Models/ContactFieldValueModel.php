<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Contact custom-field values pivot.
 * Not tenant-scoped directly (scoped through contact_id ownership).
 */
class ContactFieldValueModel extends Model
{
    protected $table      = 'contact_field_values';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $updatedField   = 'updated_at';
    protected $createdField   = 'created_at';

    protected $allowedFields = ['contact_id', 'custom_field_id', 'value'];

    /**
     * Return all field values for a contact as a keyed array
     * { field_key => value }.
     *
     * @param bool $includeRestricted When false, fields flagged restricted
     *                                (owner/admin-only) are omitted — pass false
     *                                for agent-role viewers.
     */
    public function getForContact(int $contactId, bool $includeRestricted = true): array
    {
        $builder = $this->db->table('contact_field_values cfv')
            ->join('custom_fields cf', 'cf.id = cfv.custom_field_id')
            ->where('cfv.contact_id', $contactId)
            ->select('cf.field_key, cfv.value');

        if (! $includeRestricted) {
            $builder->where('cf.restricted', 0);
        }

        $rows = $builder->get()->getResultArray();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['field_key']] = $row['value'];
        }
        return $result;
    }

    /**
     * Upsert a single field value for a contact.
     */
    public function setValue(int $contactId, int $customFieldId, ?string $value): void
    {
        $existing = $this->where('contact_id', $contactId)
                         ->where('custom_field_id', $customFieldId)
                         ->first();

        if ($existing !== null) {
            $this->update($existing['id'] ?? $existing->id, ['value' => $value]);
        } else {
            $this->insert([
                'contact_id'      => $contactId,
                'custom_field_id' => $customFieldId,
                'value'           => $value,
            ]);
        }
    }

    /**
     * Bulk-upsert from a { field_key => value } map.
     * Looks up custom_field_id by field_key within the given tenant.
     */
    public function bulkSetForContact(int $contactId, int $tenantId, array $fieldValues): void
    {
        if (empty($fieldValues)) {
            return;
        }

        $keys    = array_keys($fieldValues);
        $cfRows  = $this->db->table('custom_fields')
            ->where('tenant_id', $tenantId)
            ->whereIn('field_key', $keys)
            ->where('deleted_at', null) // CI4 renders IS NULL (no whereNull())
            ->get()->getResultArray();

        foreach ($cfRows as $cf) {
            $key   = $cf['field_key'];
            $value = $fieldValues[$key] ?? null;
            if ($value !== null && $value !== '') {
                $this->setValue($contactId, (int) $cf['id'], (string) $value);
            }
        }
    }
}
