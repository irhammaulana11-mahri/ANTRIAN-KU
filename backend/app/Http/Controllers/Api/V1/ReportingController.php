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

        return response()->json(['data' => $this->report($request)]);
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
        $report = $this->report($request);
        $statusLabels = ['WAITING' => 'Menunggu', 'ASSIGNED' => 'Ditugaskan', 'CALLED' => 'Dipanggil', 'SERVING' => 'Dilayani', 'COMPLETED' => 'Selesai', 'SKIPPED' => 'Dilewati', 'CANCELLED' => 'Dibatalkan', 'NO_SHOW' => 'Tidak hadir'];

        return response()->streamDownload(function () use ($report, $statusLabels): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $row = fn (array $cells) => fputcsv($out, $cells, ';');
            $row(['Periode', substr($report['period']['from'], 0, 10).' s/d '.substr($report['period']['to'], 0, 10)]);
            $row([]);
            $row(['Ringkasan', 'Nilai']);
            $row(['Total antrean', $report['total_queues']]);
            $row(['Terlayani', $report['served_customers']]);
            $row(['Masih aktif', $report['waiting_customers']]);
            $row(['Batal/dilewati/tidak hadir', $report['cancelled_customers']]);
            $row(['Rata-rata tunggu (detik)', $report['average_wait_seconds'] ?? '']);
            $row(['Rata-rata pelayanan (detik)', $report['average_service_seconds'] ?? '']);
            $row([]);
            $row(['Status', 'Jumlah']);
            foreach ($report['by_status'] as $item) { $row([$statusLabels[$item['status']] ?? $item['status'], $item['total']]); }
            $row([]);
            $row(['Kanal', 'Jumlah']);
            foreach ($report['by_channel'] as $item) { $row([$item['channel'] === 'ONLINE' ? 'Online' : 'Di kantor', $item['total']]); }
            $row([]);
            $row(['Layanan', 'Total', 'Terlayani', 'Batal', 'Aktif']);
            foreach ($report['by_service'] as $item) { $row([$item['service_name'], $item['total'], $item['served'], $item['cancelled'], $item['active']]); }
            if ($report['by_unit'] !== []) {
                $row([]);
                $row(['Unit', 'Total', 'Terlayani', 'Batal', 'Aktif']);
                foreach ($report['by_unit'] as $item) { $row([$item['unit_name'], $item['total'], $item['served'], $item['cancelled'], $item['active']]); }
            }
            $row([]);
            $row(['Petugas', 'Terlayani', 'Rata-rata pelayanan (detik)']);
            foreach ($report['employee_productivity'] as $item) { $row([$item['employee_name'], $item['served_count'], $item['average_service_seconds'] ?? '']); }
            $row([]);
            $row(['Tanggal', 'Total', 'Terlayani', 'Batal', 'Aktif']);
            foreach ($report['daily'] as $item) { $row([$item['date'], $item['total'], $item['served'], $item['cancelled'], $item['active']]); }
            fclose($out);
        }, 'laporan-antrian.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function report(Request $request): array
    {
        $v = $request->validate([
            'period' => ['nullable', 'in:today,month,monthly,custom'],
            'month' => ['nullable', 'required_if:period,monthly', 'date_format:Y-m'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'uuid'],
            // 'none' = hanya antrian cabang (tanpa unit).
            'unit_id' => ['nullable', 'regex:/^(none|[0-9a-fA-F-]{36})$/'],
            'employee_id' => ['nullable', 'uuid'],
        ]);

        return $this->reports->summary($request->user(), $v['branch_id'] ?? null, $v['period'] ?? 'today', $v['from'] ?? null, $v['to'] ?? null, $v['month'] ?? null, $v['unit_id'] ?? null, $v['employee_id'] ?? null);
    }
}
