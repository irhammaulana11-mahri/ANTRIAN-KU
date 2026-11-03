<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Counter;
use App\Models\Employee;
use App\Models\PriorityType;
use App\Models\Queue;
use App\Models\QueueAssignment;
use App\Models\ServiceType;
use App\Services\EmployeeStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Papan supervisor: antrian aktif hari ini, status petugas, loket, dan opsi yang dibutuhkan untuk
 * assignment manual, transfer, dan verifikasi prioritas — dalam satu permintaan.
 */
class SupervisorController extends Controller
{
    private const ACTIVE = [Queue::WAITING, Queue::ASSIGNED, Queue::CALLED, Queue::SERVING];

    public function __construct(private readonly EmployeeStatusService $statuses) {}

    public function board(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('queues.assign'), 403);
        $branchId = $request->attributes->get('branch_id');
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');
        $actor = $request->user();
        $unitId = $actor->unit_id;

        $queues = Queue::query()->with(['serviceType:id,service_name,queue_prefix', 'priorityType:id,priority_code,priority_name', 'activeAssignment.employee:id,employee_name', 'activeAssignment.counter:id,counter_code,counter_name'])
            ->where('branch_id', $branchId)->whereDate('queue_date', now()->toDateString())->whereIn('status', self::ACTIVE)
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->orderBy('created_at')->orderBy('sequence_no')->get();

        $activeByEmployee = QueueAssignment::query()->with('queue:id,queue_number')->where('branch_id', $branchId)->whereNull('ended_at')->get()->keyBy('employee_id');
        $employees = Employee::query()->with(['serviceTypes:id', 'counters:id'])->where('branch_id', $branchId)->where('is_active', true)
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->orderBy('employee_name')->get();
        $busyCounters = QueueAssignment::query()->with('employee:id,employee_name')->where('branch_id', $branchId)->whereNull('ended_at')->get()->keyBy('counter_id');

        return response()->json(['data' => [
            'server_time' => now()->toIso8601String(),
            'queues' => $queues->map(fn (Queue $queue): array => [
                'id' => $queue->id,
                'queue_number' => $queue->queue_number,
                'customer_name' => $queue->customer_name,
                'service_type_id' => $queue->service_type_id,
                'service_name' => $queue->serviceType?->service_name,
                'priority' => $queue->priorityType ? ['id' => $queue->priorityType->id, 'code' => $queue->priorityType->priority_code, 'name' => $queue->priorityType->priority_name] : null,
                'priority_verified' => $queue->priority_verified_at !== null,
                'status' => $queue->status,
                'registration_channel' => $queue->registration_channel,
                'registered_at' => $queue->created_at?->toIso8601String(),
                'called_at' => $queue->called_at?->toIso8601String(),
                'employee' => $queue->activeAssignment?->employee ? ['id' => $queue->activeAssignment->employee->id, 'name' => $queue->activeAssignment->employee->employee_name] : null,
                'counter' => $queue->activeAssignment?->counter ? ['id' => $queue->activeAssignment->counter->id, 'code' => $queue->activeAssignment->counter->counter_code, 'name' => $queue->activeAssignment->counter->counter_name] : null,
            ])->values(),
            'employees' => $employees->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'employee_name' => $employee->employee_name,
                'status' => $this->statuses->current($employee),
                'is_available' => $this->statuses->isAvailable($employee),
                'service_type_ids' => $employee->serviceTypes->pluck('id')->values(),
                'counter_ids' => $employee->counters->pluck('id')->values(),
                'active_queue_number' => $activeByEmployee->get($employee->id)?->queue?->queue_number,
            ])->values(),
            'counters' => Counter::query()->where('branch_id', $branchId)->where('is_active', true)->when($unitId, fn ($q) => $q->where('unit_id', $unitId))->orderBy('counter_code')->get(['id', 'counter_code', 'counter_name', 'counter_type'])->map(fn (Counter $counter): array => [
                'id' => $counter->id, 'counter_code' => $counter->counter_code, 'counter_name' => $counter->counter_name, 'counter_type' => $counter->counter_type,
                'in_use_by' => $busyCounters->get($counter->id)?->employee?->employee_name,
            ])->values(),
            'services' => ServiceType::query()->where('branch_id', $branchId)->where('is_active', true)->orderBy('service_name')->get(['id', 'service_name', 'queue_prefix']),
            'priorities' => PriorityType::query()->where('branch_id', $branchId)->where('is_active', true)->orderBy('weight')->get(['id', 'priority_code', 'priority_name', 'weight']),
        ]]);
    }
}
