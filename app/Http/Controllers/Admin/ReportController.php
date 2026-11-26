<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminReportService;
use App\Services\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operator report: credit spend (charged to users), provider cost (charged to us by
 * Pixelforge) and profit, per day and per user, for a chosen date range.
 */
class ReportController extends Controller
{
    public function index(Request $request, AdminReportService $report): Response
    {
        [$start, $end] = $report->range($request->query('from'), $request->query('to'));

        $perDay = $report->rows($start, $end, 'day');

        return Inertia::render('Admin/Report', [
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'max_days' => AdminReportService::MAX_DAYS],
            'per_day' => $perDay->values(),
            'per_user' => $report->rows($start, $end, 'user')->values(),
            'per_day_user' => $report->rows($start, $end, 'day_user')->values(),
            ...$report->summarise($perDay, $start, $end),
            'reconciliation' => $report->reconcile($perDay, $start, $end),
        ]);
    }

    /** CSV download of one view (?view=day|user|day_user) for the chosen range. */
    public function export(Request $request, AdminReportService $report): StreamedResponse
    {
        $view = in_array($request->query('view'), ['day', 'user', 'day_user'], true) ? $request->query('view') : 'day';
        [$start, $end] = $report->range($request->query('from'), $request->query('to'));
        $rows = $report->rows($start, $end, $view);

        $filename = "report-{$view}-{$start->toDateString()}-to-{$end->toDateString()}.csv";

        // Names and emails are user-controlled: neutralise spreadsheet formula injection.
        $safe = fn (?string $v) => $v !== null && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

        return response()->streamDownload(function () use ($rows, $view, $safe) {
            $out = fopen('php://output', 'w');
            $head = array_merge(
                $view !== 'user' ? ['day_utc'] : [],
                $view !== 'day' ? ['user_id', 'name', 'email'] : [],
                ['generations', 'charged_usd', 'provider_cost_usd', 'profit_usd'],
            );
            fputcsv($out, $head);
            foreach ($rows as $r) {
                fputcsv($out, array_merge(
                    $view !== 'user' ? [$r['day']] : [],
                    $view !== 'day' ? [$r['user_id'], $safe($r['name']), $safe($r['email'])] : [],
                    [$r['generations'], Money::toDecimal($r['charged_nano']), Money::toDecimal($r['cost_nano']), Money::toDecimal($r['profit_nano'])],
                ));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
