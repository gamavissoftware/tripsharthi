<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Travel\TravelReportService;

/** Owner/admin business reports for the travel vertical. */
class TravelReportsController extends TravelBaseController
{
    public function index()
    {
        $to = (string) ($this->request->getGet('to') ?: date('Y-m-d'));
        $from = (string) ($this->request->getGet('from') ?: date('Y-m-d', strtotime($to . ' -29 days')));
        try { return $this->ok((new TravelReportService())->report(CurrentUser::tenantId(), $from, $to)); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
    }

    /** GET /travel/reports/ads?from&to — leads, quotes, bookings, revenue and ROAS by first-touch campaign. */
    public function ads()
    {
        $to = (string) ($this->request->getGet('to') ?: date('Y-m-d'));
        $from = (string) ($this->request->getGet('from') ?: date('Y-m-d', strtotime($to . ' -89 days')));
        try { return $this->ok((new TravelReportService())->ads(CurrentUser::tenantId(), $from, $to)); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage(), 'kind' => 'invalid'], 422); }
    }
}
