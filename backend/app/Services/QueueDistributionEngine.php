<?php

namespace App\Services;

use App\Events\EmployeeStatusChanged;
use App\Events\QueueAssigned;
use App\Models\DistributionCursor;
use App\Models\Employee;
use App\Models\EmployeeStatusLog;
use App\Models\Queue;
use App\Models\QueueAssignment;
use App\Models\QueueTransition;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueDistributionEngine
{
    public function __construct(private readonly EmployeeStatusService $statuses) {}

    public function assignNext(string $branchId, string $serviceTypeId, ?User $actor = null): ?QueueAssignment
    {
        return DB::transaction(function () use ($branchId, $serviceTypeId, $actor): ?QueueAssignment {
            $service = ServiceType::query()->whereKey($serviceTypeId)->where('branch_id', $branchId)->where('is_active', true)->firstOrFail();
            $queues = Queue::query()->with(['priorityType'])->where('branch_id', $branchId)->where('service_type_id', $service->id)->where('status', Queue::WAITING)->orderBy('created_at')->lockForUpdate()->get();
            if ($queues->isEmpty()) {
                return null;
            }
            $queue = (new PriorityQueueStrategy)->chooseQueue($queues);
            $candidateEmployees = $this->eligibleEmployees($branchId, $service->id);
            $employee = $this->chooseRoundRobinEmployee($branchId, $service->id, $candidateEmployees);
            if (! $employee) {
                return null;
            }
            $counter = $this->availableCounter($employee);
            if (! $counter) {
                return null;
            }

            return $this->createAssignment($queue, $employee, $counter, 'AUTO', null, $actor);
        }, 5);
    }

    /**
     * Mencoba meng-assign seluruh antrian WAITING di cabang ke petugas yang tersedia.
     * Dipanggil saat petugas menjadi AVAILABLE (selesai layanan, lewati, kembali dari istirahat).
     *
     * @return int jumlah assignment yang berhasil dibuat
     */
    public function assignPending(string $branchId, int $limit = 50): int
    {
        $count = 0;
        $serviceIds = ServiceType::query()->where('branch_id', $branchId)->where('is_active', true)->orderBy('service_code')->pluck('id');
        foreach ($serviceIds as $serviceId) {
            while ($count < $limit && $this->assignNext($branchId, $serviceId) !== null) {
                $count++;
            }
        }

        return $count;
    }

    public function assignAfterCompletion(Queue $completedQueue): ?QueueAssignment
    {
        return $this->assignNext($completedQueue->branch_id, $completedQueue->service_type_id);
    }

    public function manualAssign(Queue $queue, Employee $employee, string $counterId, User $actor, string $reason): QueueAssignment
    {
        return DB::transaction(function () use ($queue, $employee, $counterId, $actor, $reason): QueueAssignment {
            $lockedQueue = Queue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            $lockedEmployee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $counter = DB::table('counters')->where('id', $counterId)->whereNull('deleted_at')->where('branch_id', $lockedQueue->branch_id)->where('is_active', true)->lockForUpdate()->first();
            if ($actor->branch_id !== null && $actor->branch_id !== $lockedQueue->branch_id) {
                abort(403, 'Queue bukan milik cabang Anda.');
            }
            if ($lockedEmployee->branch_id !== $lockedQueue->branch_id || ! $counter || $lockedQueue->service_type_id === null) {
                abort(422, 'Employee, loket, dan queue harus berada pada cabang yang sama.');
            }
            if (! $lockedEmployee->serviceTypes()->whereKey($lockedQueue->service_type_id)->exists()) {
                abort(422, 'Petugas tidak memiliki kompetensi layanan queue.');
            }
            if (! $this->statuses->isAvailable($lockedEmployee) || $lockedEmployee->activeAssignments()->exists() || $lockedEmployee->activeServiceSessions()->exists()) {
                abort(409, 'Petugas tidak tersedia untuk assignment.');
            }
            if (QueueAssignment::query()->where('counter_id', $counterId)->whereNull('ended_at')->exists()) {
                abort(409, 'Loket sedang digunakan.');
            }
            if ($lockedQueue->status !== Queue::WAITING) {
                abort(409, 'Queue hanya dapat di-assign dari status WAITING.');
            }

            return $this->createAssignment($lockedQueue, $lockedEmployee, (object) $counter, 'MANUAL', $reason, $actor);
        }, 5);
    }

    private function eligibleEmployees(string $branchId, string $serviceTypeId): Collection
    {
        $employees = Employee::query()->where('branch_id', $branchId)->where('is_active', true)->whereHas('serviceTypes', fn ($query) => $query->whereKey($serviceTypeId))->orderBy('id')->lockForUpdate()->get();

        return $employees->filter(fn (Employee $employee): bool => $this->statuses->isAvailable($employee) && ! $employee->activeAssignments()->exists() && ! $employee->activeServiceSessions()->exists())->values();
    }

    private function chooseRoundRobinEmployee(string $branchId, string $serviceTypeId, Collection $employees): ?Employee
    {
        DB::table('distribution_cursors')->insertOrIgnore(['id' => (string) Str::uuid(), 'branch_id' => $branchId, 'service_type_id' => $serviceTypeId, 'last_employee_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        $cursor = DistributionCursor::query()->where('branch_id', $branchId)->where('service_type_id', $serviceTypeId)->lockForUpdate()->firstOrFail();
        $employee = (new RoundRobinStrategy)->chooseEmployee($employees, $cursor);
        if ($employee) {
            $cursor->update(['last_employee_id' => $employee->id, 'updated_at' => now()]);
        }

        return $employee;
    }

    private function availableCounter(Employee $employee): ?object
    {
        return DB::table('counters')->join('employee_counters', 'employee_counters.counter_id', '=', 'counters.id')->where('employee_counters.employee_id', $employee->id)->where('counters.branch_id', $employee->branch_id)->whereNull('counters.deleted_at')->where('counters.is_active', true)->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('queue_assignments')->whereColumn('queue_assignments.counter_id', 'counters.id')->whereNull('queue_assignments.ended_at');
        })->orderBy('counters.counter_code')->lockForUpdate()->first();
    }

    private function createAssignment(Queue $queue, Employee $employee, object $counter, string $type, ?string $reason, ?User $actor): QueueAssignment
    {
        $assignment = QueueAssignment::create(['id' => (string) Str::uuid(), 'branch_id' => $queue->branch_id, 'queue_id' => $queue->id, 'employee_id' => $employee->id, 'counter_id' => $counter->id, 'assignment_type' => $type, 'reason' => $reason, 'assigned_at' => now()]);
        $from = $queue->status;
        $queue->update(['status' => Queue::ASSIGNED]);
        EmployeeStatusLog::create(['id' => (string) Str::orderedUuid(), 'branch_id' => $employee->branch_id, 'employee_id' => $employee->id, 'from_status' => EmployeeStatusService::AVAILABLE, 'to_status' => EmployeeStatusService::BUSY, 'reason' => 'Queue assignment', 'heartbeat_at' => null, 'recorded_at' => now()]);
        EmployeeStatusChanged::dispatch($employee->branch_id, ['employee_id' => $employee->id, 'status' => EmployeeStatusService::BUSY], $employee->id);
        QueueTransition::create(['id' => (string) Str::uuid(), 'branch_id' => $queue->branch_id, 'queue_id' => $queue->id, 'from_status' => $from, 'to_status' => Queue::ASSIGNED, 'actor_id' => $actor?->id, 'reason' => $reason ?? 'Automatic queue assignment', 'transitioned_at' => now()]);
        QueueAssigned::dispatch($queue->branch_id, ['queue_id' => $queue->id, 'queue_number' => $queue->queue_number, 'employee_id' => $employee->id, 'counter_id' => $counter->id, 'status' => Queue::ASSIGNED], $employee->id);

        return $assignment->load(['queue', 'employee', 'counter']);
    }
}
