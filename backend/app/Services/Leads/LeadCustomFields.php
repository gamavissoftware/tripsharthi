<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Every question on a lead-ad form becomes a custom field on the contact.
 *
 * ContactFieldValueModel::bulkSetForContact() only writes values for fields
 * that already exist, so without this every qualifying answer would be
 * silently dropped. Shared by the webhook pipeline and the month archive import.
 */
final class LeadCustomFields
{
    /**
     * @param array<string,string>           $customFields key => value (as MetaLeadMapper produced them)
     * @param array<int,array<string,mixed>> $fieldData    Meta field_data, for the question labels
     */
    public static function ensure(int $tenantId, array $customFields, array $fieldData): void
    {
        if ($customFields === []) {
            return;
        }
        $db       = db_connect();
        $existing = array_column(
            $db->table('custom_fields')->select('field_key')
                ->where('tenant_id', $tenantId)->whereIn('field_key', array_keys($customFields))
                ->where('deleted_at', null)->get()->getResultArray(),
            'field_key'
        );
        $labels = [];
        foreach ($fieldData as $f) {
            $labels[MetaLeadMapper::fieldKey((string) ($f['name'] ?? ''))] = trim((string) ($f['name'] ?? ''));
        }
        $now  = date('Y-m-d H:i:s');
        $sort = 100;
        foreach (array_keys($customFields) as $key) {
            if (in_array($key, $existing, true)) {
                continue;
            }
            $label = ucfirst(str_replace('_', ' ', rtrim($labels[$key] ?? (string) $key, '?')));
            $db->table('custom_fields')->insert([
                'tenant_id'  => $tenantId,
                'label'      => mb_substr($label, 0, 100),
                'field_key'  => $key,
                'type'       => 'text',
                'sort_order' => $sort++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
