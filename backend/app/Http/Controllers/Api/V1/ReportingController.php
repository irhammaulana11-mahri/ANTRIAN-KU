<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingController extends Controller
{
    public function __construct(private readonly ReportingService $reports) {}

    public function summary(Request $request)
    {
        abort_unless($request->user()->can('reports.view'), 403);
        $validated = $request->validate(['period' => ['nullable', 'in:today,month,custom'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'branch_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => $this->reports->summary($request->user(), $validated['branch_id'] ?? null, $validated['period'] ?? 'today', $validated['from'] ?? null, $validated['to'] ?? null)]);
    }

    public function audit(Request $request)
    {
        abort_unless($request->user()->can('audit.view'), 403);
        $validated = $request->validate(['branch_id' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->reports->audit($request->user(), $validated['branch_id'] ?? null, (int) ($validated['per_page'] ?? 50)));
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('reports.view'), 403);
        $validated = $request->validate(['period' => ['nullable', 'in:today,month,custom'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'branch_id' => ['nullable', 'uuid']]);
        $report = $this->reports->summary($request->user(), $validated['branch_id'] ?? null, $validated['period'] ?? 'today', $validated['from'] ?? null, $validated['to'] ?? null);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Metrik', 'Nilai'], ';');
            foreach (['total_queues', 'served_customers', 'waiting_customers', 'cancelled_customers', 'average_wait_seconds', 'average_service_seconds'] as $metric) {
                fputcsv($output, [$metric, $report[$metric] ?? ''], ';');
            }
            fputcsv($output, [], ';');
            fputcsv($output, ['Layanan', 'Total', 'Terlayani'], ';');
            foreach ($report['by_service'] as $row) {
                fputcsv($output, [$row['service_name'], $row['total'], $row['served']], ';');
            }
            fclose($output);
        }, 'sqms-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
