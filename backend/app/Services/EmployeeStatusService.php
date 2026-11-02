<?php

namespace App\Services;

use App\Events\EmployeeStatusChanged;
use App\Models\Employee;
use App\Models\EmployeeStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeStatusService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public const AVAILABLE = 'AVAILABLE';

    public const BUSY = 'BUSY';

    public const BREAK = 'BREAK';

    public const OFFLINE = 'OFFLINE';

    public const CLOSED = 'CLOSED';

    public function current(Employee $employee): string
    {
        return $employee->statusLogs()->orderByDesc('recorded_at')->orderByDesc('id')->value('to_status') ?? self::OFFLINE;
    }

    public function isAvailable(Employee $employee): bool
    {
        $latest = $employee->statusLogs()->orderByDesc('recorded_at')->orderByDesc('id')->first();
        if (! $latest || $latest->to_status !== self::AVAILABLE || ! $latest->heartbeat_at) {
            return false;
        }

        $timeout = (int) (DB::table('queue_settings')->where('branch_id', $employee->branch_id)->value('stale_heartbeat_seconds') ?? 90);

        return $latest->heartbeat_at->greaterThanOrEqualTo(now()->subSeconds($timeout));
    }

    public function setStatus(Employee $employee, string $status, User $actor, ?string $reason = null): EmployeeStatusLog
    {
        return DB::transaction(function () use ($employee, $status, $actor, $reason): EmployeeStatusLog {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($actor->branch_id !== null && $actor->branch_id !== $locked->branch_id) {
                abort(403, 'Petugas bukan milik cabang Anda.');
            }
            $this->authorizeActor($locked, $actor);
            if ($status === self::BUSY) {
                abort(422, 'Status BUSY hanya diatur otomatis oleh sistem saat petugas menerima antrian.');
            }
            if ($locked->activeServiceSessions()->exists() || $locked->activeAssignments()->exists()) {
                abort(409, 'Petugas masih memiliki antrian aktif. Selesaikan atau lewati antrian terlebih dahulu.');
            }
            if ($this->current($locked) === $status && $status !== self::AVAILABLE) {
                return $locked->statusLogs()->orderByDesc('recorded_at')->orderByDesc('id')->firstOrFail();
            }

            $log = EmployeeStatusLog::create([
                'id' => (string) Str::orderedUuid(),
                'branch_id' => $locked->branch_id,
                'employee_id' => $locked->id,
                'from_status' => $this->current($locked),
                'to_status' => $status,
                'reason' => $reason,
                'heartbeat_at' => $status === self::AVAILABLE ? now() : null,
                'recorded_at' => now(),
            ]);
            EmployeeStatusChanged::dispatch($locked->branch_id, ['employee_id' => $locked->id, 'status' => $log->to_status, 'recorded_at' => $log->recorded_at?->toIso8601String()], $locked->id);
            $this->audit->record('employee.status_changed', Employee::class, $locked->id, $locked->branch_id, $actor->id, ['status' => $log->from_status], ['status' => $log->to_status], ['reason' => $reason]);

            return $log;
        }, 5);
    }

    /**
     * Memperbarui heartbeat tanpa mengubah status petugas.
     * Status BREAK/BUSY/OFFLINE tidak boleh berubah hanya karena browser mengirim detak.
     */
    public function heartbeat(Employee $employee, User $actor): EmployeeStatusLog
    {
        return DB::transaction(function () use ($employee, $actor): EmployeeStatusLog {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($locked, $actor);
            $latest = $locked->statusLogs()->orderByDesc('recorded_at')->orderByDesc('id')->first();
            if (! $latest) {
                return $this->setStatus($locked, self::OFFLINE, $actor, 'Heartbeat pertama');
            }
            $latest->update(['heartbeat_at' => now()]);

            return $latest->refresh();
        });
    }

    private function authorizeActor(Employee $employee, User $actor): void
    {
        if ($actor->branch_id !== null && $actor->branch_id !== $employee->branch_id) {
            abort(403, 'Petugas bukan milik cabang Anda.');
        }
        if (! $actor->can('queues.assign') && $employee->user_id !== $actor->id) {
            abort(403, 'Anda hanya dapat mengubah status diri sendiri.');
        }
    }
}
