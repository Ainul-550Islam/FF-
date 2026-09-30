<?php

namespace App\Http\Controllers;

use App\Services\MarketingAnalyticsExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MarketingAnalyticsExportController
{
    /**
     * Stream a CSV report for marketing performance, funnel, campaigns, or UTM governance.
     */
    public function export(Request $request, MarketingAnalyticsExportService $exportService): Response
    {
        $type = (string) $request->query('type', 'utm');
        $filters = [
            'period_start' => $request->query('period_start'),
            'period_end' => $request->query('period_end'),
            'source' => $request->query('source'),
            'medium' => $request->query('medium'),
        ];

        $export = $exportService->export($type, $filters);

        return response($export['content'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $export['filename']),
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ]);
    }
}
