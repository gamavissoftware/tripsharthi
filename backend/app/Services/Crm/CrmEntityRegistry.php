<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\AccountModel;
use App\Models\ContactModel;
use App\Models\CustomObjectRecordModel;
use App\Models\DealModel;
use App\Models\TicketModel;

/**
 * The keystone of the CRM productivity layer (Phase G). One place that defines,
 * per CRM entity, the model and the WHITELISTS of fields that may be filtered,
 * searched, sorted, shown, or bulk-set. Every other service (FilterEngine,
 * search, bulk, import, merge, recycle bin) reads from here — and because every
 * field is whitelisted, untrusted column names can never reach SQL.
 *
 * NOTE: only CRM-owned tables appear here. Messaging tables (conversations,
 * messages, templates, campaigns, waba_accounts) are intentionally absent —
 * they are read-only to the CRM and never written by this layer.
 */
final class CrmEntityRegistry
{
    /** @var array<string, array> */
    private const ENTITIES = [
        'contact' => [
            'model'        => ContactModel::class,
            'label'        => 'Contacts',
            'route'        => '/contacts',
            'searchable'   => ['name', 'wa_number', 'email'],
            'filterable'   => ['status', 'source', 'lifecycle_stage', 'owner_id', 'account_id', 'lead_score', 'opt_in'],
            'sortable'     => ['name', 'lead_score', 'score_tier', 'lifecycle_stage', 'status', 'created_at', 'updated_at', 'last_inbound_at'],
            'columns'      => ['name', 'wa_number', 'email', 'lifecycle_stage', 'lead_score', 'score_tier', 'status', 'owner_id'],
            'bulk_set'     => ['owner_id', 'status', 'lifecycle_stage'],
            'supports_tags'=> true,
            'dedupe'       => ['wa_number', 'email'],
        ],
        'account' => [
            'model'        => AccountModel::class,
            'label'        => 'Accounts',
            'route'        => '/accounts',
            'searchable'   => ['name', 'domain'],
            'filterable'   => ['type', 'industry', 'owner_id'],
            'sortable'     => ['name', 'annual_revenue', 'created_at', 'updated_at'],
            'columns'      => ['name', 'industry', 'type', 'owner_id', 'domain'],
            'bulk_set'     => ['owner_id', 'type'],
            'supports_tags'=> false,
            'dedupe'       => ['domain', 'name'],
        ],
        'deal' => [
            'model'        => DealModel::class,
            'label'        => 'Deals',
            'route'        => '/deals',
            'searchable'   => ['title'],
            'filterable'   => ['pipeline_id', 'stage_id', 'status', 'owner_id', 'account_id', 'primary_contact_id'],
            'sortable'     => ['title', 'value_amount', 'expected_close_date', 'created_at', 'updated_at'],
            'columns'      => ['title', 'value_amount', 'stage_id', 'status', 'owner_id', 'expected_close_date'],
            'bulk_set'     => ['owner_id', 'stage_id', 'status'],
            'supports_tags'=> false,
            'dedupe'       => [],
        ],
        'ticket' => [
            'model'        => TicketModel::class,
            'label'        => 'Tickets',
            'route'        => '/tickets',
            'searchable'   => ['subject'],
            'filterable'   => ['status', 'priority', 'owner_id', 'source', 'contact_id', 'account_id'],
            'sortable'     => ['subject', 'priority', 'created_at', 'updated_at', 'sla_due_at'],
            'columns'      => ['subject', 'status', 'priority', 'owner_id', 'source'],
            'bulk_set'     => ['owner_id', 'status', 'priority'],
            'supports_tags'=> false,
            'dedupe'       => [],
        ],
        'custom_object_record' => [
            'model'        => CustomObjectRecordModel::class,
            'label'        => 'Records',
            'route'        => '/objects',
            'searchable'   => ['name'],
            'filterable'   => ['custom_object_id', 'owner_id'],
            'sortable'     => ['name', 'created_at', 'updated_at'],
            'columns'      => ['name', 'owner_id', 'created_at'],
            'bulk_set'     => ['owner_id'],
            'supports_tags'=> false,
            'dedupe'       => [],
            'requires'     => ['custom_object_id'], // list must be scoped to one object
        ],
    ];

    public static function has(string $entity): bool
    {
        return isset(self::ENTITIES[$entity]);
    }

    /** @return array entity config; throws on unknown entity. */
    public static function get(string $entity): array
    {
        if (! isset(self::ENTITIES[$entity])) {
            throw new \InvalidArgumentException("Unknown CRM entity '{$entity}'.");
        }
        return self::ENTITIES[$entity];
    }

    /** Fresh, tenant-scoped model instance for an entity. */
    public static function model(string $entity, int $tenantId): \App\Models\BaseModel
    {
        $class = self::get($entity)['model'];
        return (new $class())->setTenant($tenantId);
    }

    /** @return string[] entity keys */
    public static function keys(): array
    {
        return array_keys(self::ENTITIES);
    }
}
