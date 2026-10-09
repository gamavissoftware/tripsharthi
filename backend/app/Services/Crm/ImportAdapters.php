<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\CustomObjectFieldModel;
use App\Models\PipelineModel;
use App\Models\PipelineStageModel;
use RuntimeException;

/**
 * Per-entity import configuration for the generic CrmImporter (Phase G4).
 *
 * The contact importer (App\Services\Leads\Importer) is intentionally NOT routed
 * through here — it keeps its battle-tested wa_number + dedupe pipeline. These
 * adapters cover the additional CRM objects: account, deal, custom_object_record.
 *
 * Each adapter is a small config array:
 *   fields   => [field_key => Label]   importable/mappable columns
 *   required => [field_key, …]         must be non-empty after mapping
 *   dedupe   => [field_key, …]         match an existing row on the first present key
 *   build    => fn(array $vals): array clean/transform a mapped row into model data
 */
final class ImportAdapters
{
    public static function supports(string $entity): bool
    {
        return in_array($entity, ['account', 'deal', 'custom_object_record'], true);
    }

    /**
     * @param array $ctx tenantId, customObjectId (for records)
     * @return array{fields:array,required:array,dedupe:array,build:callable}
     */
    public static function for(string $entity, array $ctx): array
    {
        return match ($entity) {
            'account'              => self::account(),
            'deal'                 => self::deal($ctx),
            'custom_object_record' => self::record($ctx),
            default                => throw new RuntimeException("No import adapter for '{$entity}'."),
        };
    }

    private static function account(): array
    {
        return [
            'fields' => [
                'name' => 'Name', 'domain' => 'Domain', 'industry' => 'Industry', 'type' => 'Type',
                'phone' => 'Phone', 'website' => 'Website', 'city' => 'City', 'state' => 'State', 'country' => 'Country',
            ],
            'required' => ['name'],
            'dedupe'   => ['domain', 'name'],
            'build'    => static function (array $v): array {
                $v['type'] = in_array($v['type'] ?? '', ['prospect', 'customer', 'partner', 'other'], true) ? $v['type'] : 'prospect';
                return array_filter($v, static fn ($val) => $val !== '' && $val !== null);
            },
        ];
    }

    private static function deal(array $ctx): array
    {
        // Resolve the tenant's default pipeline + first stage once, up front.
        $tenantId = (int) $ctx['tenantId'];
        $pipeline = (new PipelineModel())->ensureDefault($tenantId);
        $stages   = (new PipelineStageModel())->forPipeline($tenantId, (int) $pipeline['id']);
        if (empty($stages)) {
            throw new RuntimeException('Default pipeline has no stages — cannot import deals.');
        }
        $pipelineId = (int) $pipeline['id'];
        $stageId    = (int) $stages[0]['id'];

        return [
            'fields' => [
                'title' => 'Title', 'value_amount' => 'Value (paise)', 'status' => 'Status',
                'source' => 'Source', 'currency' => 'Currency', 'expected_close_date' => 'Expected close',
            ],
            'required' => ['title'],
            'dedupe'   => [],
            'build'    => static function (array $v) use ($pipelineId, $stageId): array {
                $out = array_filter($v, static fn ($val) => $val !== '' && $val !== null);
                $out['value_amount'] = isset($out['value_amount']) ? (int) preg_replace('/[^0-9]/', '', (string) $out['value_amount']) : 0;
                $out['status']       = $out['status'] ?? 'open';
                $out['currency']     = $out['currency'] ?? 'INR';
                $out['pipeline_id']  = $pipelineId;
                $out['stage_id']     = $stageId;
                return $out;
            },
        ];
    }

    private static function record(array $ctx): array
    {
        $tenantId = (int) $ctx['tenantId'];
        $objectId = (int) ($ctx['customObjectId'] ?? 0);
        if ($objectId <= 0) {
            throw new RuntimeException('custom_object_id is required to import records.');
        }
        $fieldRows = (new CustomObjectFieldModel())->setTenant($tenantId)->where('custom_object_id', $objectId)->findAll();
        $fieldKeys = array_column($fieldRows, 'field_key');

        $fields = ['name' => 'Name'];
        foreach ($fieldRows as $f) {
            $fields[$f['field_key']] = $f['label'] ?? $f['field_key'];
        }

        return [
            'fields'   => $fields,
            'required' => ['name'],
            'dedupe'   => ['name'],
            'build'    => static function (array $v) use ($objectId, $fieldKeys): array {
                $data = [];
                foreach ($fieldKeys as $k) {
                    if (isset($v[$k]) && $v[$k] !== '') {
                        $data[$k] = $v[$k];
                    }
                }
                return [
                    'custom_object_id' => $objectId,
                    'name'             => $v['name'] ?? '',
                    'data'             => json_encode($data),
                ];
            },
        ];
    }
}
