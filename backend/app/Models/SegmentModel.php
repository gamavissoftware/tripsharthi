<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Named/saved audience segment.
 *
 * filters JSON schema (array of condition objects):
 *   [{"field":"status","operator":"equals","value":"new"},
 *    {"field":"tag_id","operator":"in","values":[1,2]},
 *    {"field":"source","operator":"equals","value":"csv_import"}]
 *
 * Supported fields:    status, source, tag_id, opt_in
 * Supported operators: equals, not_equals, in, not_in
 */
class SegmentModel extends BaseModel
{
    protected $table      = 'segments';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'tenant_id',
        'name',
        'description',
        'filters',
    ];

    /**
     * Build a QueryBuilder scoped to contacts matching the segment filters.
     * Returns the builder so callers can call countAllResults() or get().
     */
    public function buildContactQuery(array $filters, int $tenantId): \CodeIgniter\Database\BaseBuilder
    {
        $db      = \Config\Database::connect();
        $builder = $db->table('contacts')
                      ->where('contacts.tenant_id', $tenantId)
                      ->where('contacts.deleted_at', null); // CI4 renders IS NULL (no whereNull())

        foreach ($filters as $f) {
            $f        = (array) $f; // accept stdClass (from request) or array (from stored JSON)
            $field    = $f['field']    ?? '';
            $operator = $f['operator'] ?? 'equals';
            $value    = $f['value']    ?? null;
            $values   = $f['values']   ?? [];

            switch ($field) {
                case 'status':
                case 'source':
                    if ($operator === 'equals' && $value !== null) {
                        $builder->where("contacts.{$field}", $value);
                    } elseif ($operator === 'not_equals' && $value !== null) {
                        $builder->where("contacts.{$field} !=", $value);
                    } elseif ($operator === 'in' && ! empty($values)) {
                        $builder->whereIn("contacts.{$field}", $values);
                    } elseif ($operator === 'not_in' && ! empty($values)) {
                        $builder->whereNotIn("contacts.{$field}", $values);
                    }
                    break;

                case 'opt_in':
                    $builder->where('contacts.opt_in', ($value === 'true' || $value === '1') ? 1 : 0);
                    break;

                case 'tag_id':
                    $tagId = (int) ($value ?? 0);
                    if ($tagId > 0) {
                        if ($operator === 'equals') {
                            $builder->join('contact_tags ct', 'ct.contact_id = contacts.id')
                                    ->where('ct.tag_id', $tagId);
                        } elseif ($operator === 'not_equals') {
                            $builder->whereNotIn('contacts.id',
                                $db->table('contact_tags')->select('contact_id')->where('tag_id', $tagId));
                        }
                    }
                    break;
            }
        }

        return $builder;
    }

    /** Count contacts matching a segment's filters. */
    public function countMatching(array $filters, int $tenantId): int
    {
        if (empty($filters)) {
            return (new ContactModel())->setTenant($tenantId)->countAllResults();
        }
        return (int) $this->buildContactQuery($filters, $tenantId)->countAllResults();
    }
}
