<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Travel\RateCardImportService;

/** AI-assisted rate-sheet import (owner/admin: it writes supplier COST data). */
class RateImportController extends TravelBaseController
{
    private function run(callable $fn, int $code = 200)
    {
        try { return $this->ok($fn(), $code); }
        catch (\OutOfBoundsException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'not_found'], 404); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'conflict'], 409); }
    }

    /** POST /travel/rate-import/preview — JSON {supplier_id, text, currency?, destination_id?} or multipart with `file`. */
    public function preview()
    {
        return $this->run(function () {
            $b = str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'json') ? ($this->request->getJSON(true) ?: []) : ($this->request->getPost() ?: []);
            $text = (string) ($b['text'] ?? ''); $name = 'Pasted text'; $ext = 'txt';
            $f = $this->request->getFile('file');
            if ($f && $f->isValid()) {
                if ($f->getSize() > 2_000_000) { throw new \InvalidArgumentException('That file is larger than 2 MB.'); }
                $ext = strtolower($f->getExtension() ?: pathinfo($f->getClientName(), PATHINFO_EXTENSION));
                if (! in_array($ext, ['csv', 'tsv', 'txt', 'xlsx', 'xls', 'pdf'], true)) { throw new \InvalidArgumentException('Upload a CSV, TSV, TXT or Excel (.xlsx) file.'); }
                $text = (string) file_get_contents($f->getTempName()); $name = $f->getClientName();
            }
            return (new RateCardImportService())->preview(CurrentUser::tenantId(), CurrentUser::id(), (int) ($b['supplier_id'] ?? 0), ! empty($b['destination_id']) ? (int) $b['destination_id'] : null, $name, $text, $ext, (string) ($b['currency'] ?? 'INR'));
        }, 201);
    }

    public function show($id = null) { return $this->run(fn () => (new RateCardImportService())->get(CurrentUser::tenantId(), (int) $id)); }

    /** POST /travel/rate-import/{id}/commit {accept:[idx], edits:{idx:{...}}, confirm_big:[idx]} */
    public function commit($id = null)
    {
        $b = $this->body();
        return $this->run(fn () => (new RateCardImportService())->commit(CurrentUser::tenantId(), CurrentUser::id(), (int) $id, (array) ($b['accept'] ?? []), (array) ($b['edits'] ?? []), (array) ($b['confirm_big'] ?? [])));
    }
}
