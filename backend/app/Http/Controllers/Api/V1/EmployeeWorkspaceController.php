<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeStatusRequest;
use App\Http\Requests\HeartbeatRequest;
use App\Http\Requests\ServiceActionRequest;
use App\Models\Employee;
use App\Models\Queue;
use App\Models\QueueAssignment;
use App\Models\ServiceSession;
use App\Models\User;
use App\Services\EmployeeStatusService;
use App\Services\QueueDistributionEngine;
use App\Services\QueueManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint ruang kerja petugas CS/Teller: status diri, antrian aktif, dan aksi pelayanan.
 */
class EmployeeWorkspaceController extends Controller
{
    private const ACTION_STATUS = [
        'call' => [Queue::CALLED, [Queue::ASSIGNED]],
        'recall' => [Queue::CALLED, [Queue::CALLED]],
        'start' => [Queue::SERVING, [Queue::CALLED]],
        'complete' => [Queue::COMPLETED, [Queue::SERVING]],
        'skip' => [Queue::SKIPPED, [Queue::ASSIGNED, Queue::CALLED]],
        'no-show' => [Queue::NO_SHOW, [Queue::ASSIGNED, Queue::CALLED, Queue::SERVING]],
    ];

    public function __construct(
        private readonly EmployeeStatusService $statuses,
        private readonly QueueManagementService $queues,
        private readonly QueueDistributionEngine $distribution,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('queues.view'), 403);

        return response()->json(['data' => $this->workspace($this->employeeOf($request->user()))]);
    }

    public function status(EmployeeStatusRequest $request): JsonResponse
    {
        $employee = $this->employeeOf($request->user());
        $this->statuses->setStatus($employee, $request->string('status')->toString(), $request->user(), $request->input('reason'));
        if ($this->statuses->current($employee) === EmployeeStatusService::AVAILABLE) {
            $this->distribution->assignPending($employee->branch_id);
        }

        return response()->json(['data' => $this->workspace($employee), 'message' => 'Status petugas diperbarui.']);
    }

    public function heartbeat(HeartbeatRequest $request): JsonResponse
    {
        $employee = $this->employeeOf($request->user());
        $this->statuses->heartbeat($employee, $request->user());
        if ($this->statuses->current($employee) === EmployeeStatusService::AVAILABLE) {
            $this->distribution->assignPending($employee->branch_id);
        }

        return response()->json(['data' => $this->workspace($employee), 'message' => 'Heartbeat diterima.']);
    }

    public function act(ServiceActionRequest $request, Queue $queue, string $action): JsonResponse
    {
        [$target, $allowedFrom] = self::ACTION_STATUS[$action];
        $employee = $this->employeeOf($request->user());
        if ($employee->branch_id !== $queue->branch_id) {
            abort(403, 'Queue bukan milik cabang Anda.');
        }
        if (! in_array($queue->status, $allowedFrom, true)) {
            abort(409, 'Aksi tidak dapat dilakukan pada status antrian saat ini ('.$queue->status.').');
        }

        $updated = $this->queues->changeStatus($queue, $target, $request->user(), $request->input('reason'));
        if (in_array($updated->status, [Queue::COMPLETED, Queue::SKIPPED, Queue::NO_SHOW], true)) {
            $this->distribution->assignPending($updated->branch_id);
        }

        return response()->json(['data' => $this->workspace($employee), 'message' => 'Aksi pelayanan berhasil.']);
    }

    private function employeeOf(User $user): Employee
    {
        $employee = $user->employee;
        if (! $employee || ! $employee->is_active) {
            abort(403, 'Akun ini belum terhubung dengan data petugas yang aktif.');
        }

        return $employee;
    }

    private function workspace(Employee $employee): array
    {
        $employee->load(['serviceTypes', 'counters']);
        $latest = $employee->statusLogs()->orderByDesc('recorded_at')->orderByDesc('id')->first();
        $assignment = QueueAssignment::query()->with(['queue.serviceType', 'queue.priorityType', 'counter'])
            ->where('employee_id', $employee->id)->whereNull('ended_at')->latest('assigned_at')->first();
        $session = $assignment ? ServiceSession::query()->where('queue_id', $assignment->queue_id)->whereNull('completed_at')->first() : null;

        $waiting = Queue::query()->with('serviceType')->where('branch_id', $employee->branch_id)->where('status', Queue::WAITING)
            ->whereDate('queue_date', now()->toDateString())->whereIn('service_type_id', $employee->serviceTypes->pluck('id'))->orderBy('created_at');
        $waitingCount = (clone $waiting)->count();

        return [
            'server_time' => now()->toIso8601String(),
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'employee_name' => $employee->employee_name,
                'service_types' => $employee->serviceTypes->map(fn ($s) => ['id' => $s->id, 'service_name' => $s->service_name, 'queue_prefix' => $s->queue_prefix])->values(),
                'counters' => $employee->counters->map(fn ($c) => ['id' => $c->id, 'counter_code' => $c->counter_code, 'counter_name' => $c->counter_name])->values(),
            ],
            'status' => $this->statuses->current($employee),
            'is_available' => $this->statuses->isAvailable($employee),
            'heartbeat_at' => $latest?->heartbeat_at?->toIso8601String(),
            'ready' => $employee->serviceTypes->isNotEmpty() && $employee->counters->isNotEmpty(),
            'active' => $assignment ? [
                'queue_id' => $assignment->queue_id,
                'queue_number' => $assignment->queue->queue_number,
                'customer_name' => $assignment->queue->customer_name,
                'service_name' => $assignment->queue->serviceType?->service_name,
                'service_notes' => $assignment->queue->service_notes,
                'priority_name' => $assignment->queue->priorityType?->priority_name,
                'status' => $assignment->queue->status,
                'counter' => ['id' => $assignment->counter_id, 'counter_code' => $assignment->counter?->counter_code, 'counter_name' => $assignment->counter?->counter_name],
                'registered_at' => $assignment->queue->created_at?->toIso8601String(),
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                'called_at' => $assignment->queue->called_at?->toIso8601String(),
                'service_started_at' => $session?->started_at?->toIso8601String(),
            ] : null,
            'waiting_count' => $waitingCount,
            'next' => $waiting->limit(5)->get()->map(fn (Queue $q) => ['queue_id' => $q->id, 'queue_number' => $q->queue_number, 'customer_name' => $q->customer_name, 'service_name' => $q->serviceType?->service_name])->values(),
        ];
    }
}
