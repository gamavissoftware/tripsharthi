<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\LeadImportModel;
use App\Services\Flow\JobDispatcher;
use App\Services\Leads\Importer;

/**
 * Handles 'lead_import' jobs from the durable queue.
 *
 * ── Relocation seam (Sprint 1 → Sprint 4 Phase 5) ────────────────────
 * processBatch() body VERBATIM — not one character changed.
 *
 * ── Crash / resume durability ────────────────────────────────────────
 * Importer::processBatch() commits the byte-cursor (lead_imports.cursor)
 * to the DB before returning.  A retry after a crash resumes from the
 * committed cursor — zero duplicate contact rows (keyset + dedupe).
 *
 * ── Injectable importer (for testing) ────────────────────────────────
 */
class LeadImportHandler
{
    public function __construct(
        private readonly ?Importer $importerOverride = null,
    ) {}

    public function handle(array $job, int $tenantId): void
    {
        $payload  = json_decode($job['payload'] ?? '{}', true) ?: [];
        $importId = (int) ($payload['import_id'] ?? 0);

        if ($importId <= 0 || $tenantId <= 0) {
            throw new \RuntimeException(
                "lead_import job #{$job['id']} missing import_id or tenant_id."
            );
        }

        $importRow = (new LeadImportModel())->setTenant($tenantId)->find($importId);
        if ($importRow === null) {
            log_message('warning', "lead_import: import #{$importId} not found, skipping.");
            return;
        }
        if (! in_array($importRow['status'], ['mapped', 'processing'], true)) {
            log_message('info', "lead_import: import #{$importId} status={$importRow['status']}, skipping.");
            return;
        }

        $importer = $this->importerOverride ?? new Importer(
            new LeadImportModel(),
            new ContactModel(),
            new ContactFieldValueModel()
        );

        // ── processBatch() — VERBATIM from Sprint 1 ──────────────────
        // Cursor committed to DB inside processBatch() BEFORE this line returns.
        $result = $importer->processBatch($importId, $tenantId);

        if (($result['status'] ?? '') === 'processing') {
            JobDispatcher::dispatch($tenantId, 'lead_import', [
                'import_id' => $importId,
            ]);
        }
    }
}
