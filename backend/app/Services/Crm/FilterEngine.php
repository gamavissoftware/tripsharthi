<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\BaseModel;

/**
 * Applies a whitelisted filter spec to a tenant-scoped model query (CRM Phase G).
 *
 * Spec: { "match": "and"|"or", "conditions": [ {field, op, value}, ... ] }.
 *
 * Safety:
 *  - Every `field` is validated against the per-entity whitelist; unknown fields
 *    are silently dropped, so a column name can never be injected.
 *  - A multi-condition OR is wrapped in ONE group so the tenant scope (added later
 *    by the model's paginate()/findAll() as a trailing AND) cannot be escaped by
 *    OR-precedence: result is `( …OR conditions… ) AND tenant_id = ?`. AND filters
 *    and single conditions need no group — they already AND with the tenant scope.
 */
final class FilterEngine
{
    private const OPS = ['eq', 'neq', 'contains', 'gt', 'gte', 'lt', 'lte', 'in', 'before', 'after', 'is_empty', 'is_not_empty'];

    /** Mutates and returns the model with the filter WHERE applied. */
    public function apply(BaseModel $model, array $filters, array $allowedFields): BaseModel
    {
        $conditions = $filters['conditions'] ?? [];
        if (! is_array($conditions)) {
            return $model;
        }

        // Keep only whitelisted field + known op (drop the rest silently).
        $valid = array_values(array_filter($conditions, static fn ($c) => is_array($c)
            && in_array($c['field'] ?? '', $allowedFields, true)
            && in_array($c['op'] ?? 'eq', self::OPS, true)));

        if (empty($valid)) {
            return $model; // never emit an empty "()" group
        }

        $or = strtolower((string) ($filters['match'] ?? 'and')) === 'or' && count($valid) > 1;

        // AND (or a single condition): apply directly — each clause simply ANDs
        // with the model's tenant scope, no group needed. Only the multi-condition
        // OR case needs a wrapping group so the OR can't escape the tenant scope.
        if ($or) {
            $model->groupStart();
            foreach ($valid as $i => $c) {
                $this->one($model, (string) $c['field'], (string) ($c['op'] ?? 'eq'), $c['value'] ?? null, $i > 0);
            }
            $model->groupEnd();
        } else {
            foreach ($valid as $c) {
                $this->one($model, (string) $c['field'], (string) ($c['op'] ?? 'eq'), $c['value'] ?? null, false);
            }
        }

        return $model;
    }

    private function one(BaseModel $m, string $field, string $op, mixed $value, bool $or): void
    {
        switch ($op) {
            case 'eq':           $or ? $m->orWhere($field, $value) : $m->where($field, $value); break;
            case 'neq':          $or ? $m->orWhere("{$field} !=", $value) : $m->where("{$field} !=", $value); break;
            case 'contains':     $or ? $m->orLike($field, (string) $value) : $m->like($field, (string) $value); break;
            case 'gt':
            case 'after':        $or ? $m->orWhere("{$field} >", $value) : $m->where("{$field} >", $value); break;
            case 'gte':          $or ? $m->orWhere("{$field} >=", $value) : $m->where("{$field} >=", $value); break;
            case 'lt':
            case 'before':       $or ? $m->orWhere("{$field} <", $value) : $m->where("{$field} <", $value); break;
            case 'lte':          $or ? $m->orWhere("{$field} <=", $value) : $m->where("{$field} <=", $value); break;
            case 'in':
                $arr = is_array($value) ? $value : array_map('trim', explode(',', (string) $value));
                $or ? $m->orWhereIn($field, $arr) : $m->whereIn($field, $arr);
                break;
            // $field is whitelisted (alphanumeric column) → safe to interpolate raw.
            case 'is_empty':     $or ? $m->orWhere("({$field} IS NULL OR {$field} = '')") : $m->where("({$field} IS NULL OR {$field} = '')"); break;
            case 'is_not_empty': $or ? $m->orWhere("({$field} IS NOT NULL AND {$field} != '')") : $m->where("({$field} IS NOT NULL AND {$field} != '')"); break;
        }
    }
}
