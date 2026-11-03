<?php

namespace App\Services;

use App\Events\DisplayUpdated;
use App\Events\EmployeeStatusChanged;
use App\Events\QueueCalled;
use App\Events\QueueCompleted;
use App\Events\QueueCreated;
use App\Events\QueueRecalled;
use App\Events\QueueStarted;
use App\Events\QueueStatusChanged;
use App\Events\QueueTransferred;
use App\Models\Employee;
use App\Models\EmployeeStatusLog;
use App\Models\PriorityType;
use App\Models\Queue;
use App\Models\QueueAssignment;
use App\Models\QueueCallLog;
use App\Models\QueueSequence;
use App\Models\QueueTransition;
use App\Models\ServiceSession;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QueueManagementService
{
    public function __construct(private readonly BranchAuthorizationService $branches, private readonly QueueNumberFormatter $formatter, private readonly AuditLogService $audit, private readonly BranchAvailabilityService $availability, private readonly WaitEstimator $estimator) {}

    public function register(array $data, string $channel, ?User $actor = null): array
    {
        return DB::transaction(function () use ($data, $channel, $actor): array {
            $branch = DB::table('branches')->where('id', $data['branch_id'])->whereNull('deleted_at')->where('is_active', true)->first();
            $service = ServiceType::query()->where('id', $data['service_type_id'])->where('branch_id', $data['branch_id'])->where('is_active', true)->first();
            if (! $branch || ! $service) {
                throw ValidationException::withMessages(['service_type_id' => 'Cabang atau jenis layanan tidak valid.']);
            }

            $priority = $this->resolvePriority($data['priority_type_id'] ?? null, $data['branch_id'], $actor);
            $queueDate = now()->toDateString();
            if ($channel === 'ONLINE') {
                $this->availability->lockBranch($data['branch_id']);
                $this->availability->assertOnlineAllowed($data['branch_id']);
            }
            $data['customer_phone'] = $this->normalizePhone($data['customer_phone'] ?? null);
            $sequence = $this->lockSequence($data['branch_id'], $service->id, $queueDate);
            // Cek duplikat WAJIB setelah baris sequence terkunci: kunci itu menyerialisasi semua pendaftaran
            // untuk cabang+layanan+hari yang sama, sehingga dua permintaan serentak tidak lolos bersamaan.
            $this->assertNoActiveDuplicate($data['branch_id'], $service->id, $queueDate, $data['customer_phone']);
            $nextNumber = $sequence->last_number + 1;
            $sequence->update(['last_number' => $nextNumber, 'updated_at' => now()]);

            $plainToken = bin2hex(random_bytes(48));
            $queue = Queue::create([
                'id' => (string) Str::uuid(),
                'branch_id' => $data['branch_id'],
                'unit_id' => $data['unit_id'] ?? $actor?->unit_id,
                'service_type_id' => $service->id,
                'priority_type_id' => $priority?->id,
                'queue_date' => $queueDate,
                'sequence_no' => $nextNumber,
                'queue_number' => $this->formatter->format($service->queue_prefix, $nextNumber),
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'service_notes' => $data['service_notes'] ?? null,
                'registration_channel' => $channel,
                'status' => Queue::WAITING,
                'token_hash' => hash('sha256', $plainToken),
                'priority_verified_by' => $priority && $actor ? $actor->id : null,
                'priority_verified_at' => $priority && $actor ? now() : null,
            ]);
            $this->transition($queue, null, Queue::WAITING, $actor, 'Queue registered');
            QueueCreated::dispatch($queue->branch_id, $this->eventPayload($queue));
            $this->audit->recordModel('queue.created', $queue, $actor?->id, null, $this->eventPayload($queue));

            return ['queue' => $queue->load(['serviceType', 'priorityType']), 'token' => $plainToken];
        }, 5);
    }

    public function track(string $plainToken): Queue
    {
        $queue = Queue::query()->with(['serviceType', 'priorityType', 'branch', 'activeAssignment.counter'])->where('token_hash', hash('sha256', $plainToken))->firstOrFail();
        $estimate = $this->estimator->forQueue($queue);
        $queue->setAttribute('position', $estimate['position']);
        $queue->setAttribute('ahead', $estimate['ahead']);
        $queue->setAttribute('estimated_wait_seconds', $estimate['estimated_wait_seconds']);

        return $queue;
    }

    public function cancelByToken(string $plainToken, ?string $reason = null): Queue
    {
        return DB::transaction(function () use ($plainToken, $reason): Queue {
            $queue = Queue::query()->where('token_hash', hash('sha256', $plainToken))->lockForUpdate()->firstOrFail();

            return $this->cancelLocked($queue, $reason, null);
        });
    }

    public function cancel(Queue $queue, ?User $actor, ?string $plainToken, ?string $reason): Queue
    {
        return DB::transaction(function () use ($queue, $actor, $plainToken, $reason): Queue {
            $locked = Queue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            if (! $actor && (! $plainToken || ! hash_equals((string) $locked->token_hash, hash('sha256', $plainToken)))) {
                abort(403, 'Token queue tidak valid.');
            }
            if ($actor) {
                $this->authorizeScope($locked, $actor);
            }

            return $this->cancelLocked($locked, $reason, $actor);
        });
    }

    public function verifyPriority(Queue $queue, string $priorityTypeId, User $actor, ?string $reason = null): Queue
    {
        return DB::transaction(function () use ($queue, $priorityTypeId, $actor, $reason): Queue {
            $locked = Queue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            $this->authorizeScope($locked, $actor);
            $priority = PriorityType::query()->whereKey($priorityTypeId)->where('branch_id', $locked->branch_id)->where('is_active', true)->firstOrFail();
            if (in_array($locked->status, [Queue::COMPLETED, Queue::CANCELLED, Queue::SKIPPED, Queue::NO_SHOW], true)) {
                abort(409, 'Queue sudah berstatus terminal.');
            }
            $from = $locked->status;
            $locked->update(['priority_type_id' => $priority->id, 'priority_verified_by' => $actor->id, 'priority_verified_at' => now()]);
            $this->transition($locked, $from, $from, $actor, $reason ?? 'Priority verified');
            $this->audit->recordModel('queue.priority_verified', $locked, $actor->id, ['status' => $from], ['priority_type_id' => $priority->id], ['reason' => $reason]);

            return $locked->load(['serviceType', 'priorityType']);
        });
    }

    public function transfer(Queue $queue, string $targetServiceTypeId, User $actor, string $reason): Queue
    {
        return DB::transaction(function () use ($queue, $targetServiceTypeId, $actor, $reason): Queue {
            $locked = Queue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            $this->authorizeScope($locked, $actor);
            $target = ServiceType::query()->whereKey($targetServiceTypeId)->where('branch_id', $locked->branch_id)->where('is_active', true)->firstOrFail();
            if (in_array($locked->status, [Queue::COMPLETED, Queue::CANCELLED, Queue::SKIPPED, Queue::NO_SHOW], true)) {
                abort(409, 'Queue terminal tidak dapat ditransfer.');
            }
            $from = $locked->status;
            // Antrian yang sedang dilayani ditransfer: sesi layanan harus ditutup, jika tidak petugas tertahan "melayani".
            $this->completeServiceSession($locked, 'TRANSFERRED');
            $this->releaseAssignments($locked->id);
            $sequence = $this->lockSequence($locked->branch_id, $target->id, $locked->queue_date->toDateString());
            $nextNumber = $sequence->last_number + 1;
            $sequence->update(['last_number' => $nextNumber, 'updated_at' => now()]);
            $locked->update(['service_type_id' => $target->id, 'sequence_no' => $nextNumber, 'queue_number' => $this->formatter->format($target->queue_prefix, $nextNumber), 'status' => Queue::WAITING, 'called_at' => null]);
            $this->transition($locked, $from, Queue::WAITING, $actor, $reason);
            QueueStatusChanged::dispatch($locked->branch_id, $this->eventPayload($locked));
            QueueTransferred::dispatch($locked->branch_id, $this->eventPayload($locked));
            $this->audit->recordModel('queue.transferred', $locked, $actor->id, ['status' => $from], $this->eventPayload($locked), ['reason' => $reason]);

            return $locked->load(['serviceType', 'priorityType']);
        }, 5);
    }

    public function changeStatus(Queue $queue, string $targetStatus, User $actor, ?string $reason = null): Queue
    {
        return DB::transaction(function () use ($queue, $targetStatus, $actor, $reason): Queue {
            $locked = Queue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            $this->authorizeScope($locked, $actor);
            $this->authorizeOperator($locked, $actor);
            $allowed = [
                Queue::WAITING => [Queue::CANCELLED],
                Queue::ASSIGNED => [Queue::CALLED, Queue::WAITING, Queue::CANCELLED, Queue::SKIPPED, Queue::NO_SHOW],
                Queue::CALLED => [Queue::SERVING, Queue::CALLED, Queue::SKIPPED, Queue::CANCELLED],
                Queue::SERVING => [Queue::COMPLETED, Queue::SKIPPED, Queue::NO_SHOW],
            ];
            if (! in_array($targetStatus, $allowed[$locked->status] ?? [], true)) {
                abort(409, 'Transisi status queue tidak diizinkan.');
            }
            $from = $locked->status;
            $attributes = ['status' => $targetStatus];
            if ($targetStatus === Queue::CALLED) {
                $attributes['called_at'] = now();
            }
            if ($targetStatus === Queue::COMPLETED) {
                $attributes['completed_at'] = now();
            }
            $locked->update($attributes);
            if ($targetStatus === Queue::CALLED) {
                $this->logCall($locked, $from === Queue::CALLED ? 'RECALL' : 'INITIAL');
            }
            if ($targetStatus === Queue::WAITING) {
                $this->releaseAssignments($locked->id);
            }
            if (in_array($targetStatus, [Queue::COMPLETED, Queue::SKIPPED, Queue::CANCELLED, Queue::NO_SHOW], true)) {
                $this->completeServiceSession($locked, $targetStatus);
                $this->releaseAssignments($locked->id);
            }
            $this->transition($locked, $from, $targetStatus, $actor, $reason ?? 'Queue status changed');
            $payload = $this->eventPayload($locked);
            QueueStatusChanged::dispatch($locked->branch_id, $payload);
            if (in_array($targetStatus, [Queue::CALLED, Queue::SERVING], true)) {
                $payload = $this->callPayload($locked, $payload);
            }
            $this->audit->recordModel('queue.status_changed', $locked, $actor->id, ['status' => $from], $payload, ['reason' => $reason]);
            if ($targetStatus === Queue::CALLED) {
                ($from === Queue::CALLED ? QueueRecalled::class : QueueCalled::class)::dispatch($locked->branch_id, $payload);
                DisplayUpdated::dispatch($locked->branch_id, $payload);
            }
            if ($targetStatus === Queue::SERVING) {
                $this->startServiceSession($locked);
                QueueStarted::dispatch($locked->branch_id, $payload);
            }
            if ($targetStatus === Queue::COMPLETED) {
                QueueCompleted::dispatch($locked->branch_id, $payload);
            }

            return $locked->load(['serviceType', 'priorityType']);
        }, 5);
    }

    public function history(Request $request): Builder
    {
        $user = $request->user();
        $branchId = $this->branches->resolve($user, $request->input('branch_id'));
        $serviceIds = $this->visibleServiceIds($user);

        $unitId = $this->branches->resolveUnit($user, $request->input('unit_id'));
        $date = $request->input('queue_date', now()->toDateString());

        return Queue::query()->with(['serviceType', 'priorityType'])
            ->when($serviceIds !== null, fn ($q) => $q->whereIn('service_type_id', $serviceIds))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->whereDate('queue_date', $date)
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest();
    }

    /** Petugas non-supervisor hanya melihat layanan yang menjadi kompetensinya; null = tanpa batasan. */
    private function visibleServiceIds(User $user): ?array
    {
        if ($user->can('queues.assign') || $user->can('reports.view')) {
            return null;
        }

        $employee = $user->employee;

        return $employee ? $employee->serviceTypes()->pluck('service_types.id')->all() : null;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $clean = $phone === null ? '' : preg_replace('/[^\d+]/', '', $phone);

        return $clean === '' ? null : $clean;
    }

    private function assertNoActiveDuplicate(string $branchId, string $serviceTypeId, string $queueDate, ?string $phone): void
    {
        if ($phone === null) {
            return;
        }
        $exists = Queue::query()->where('branch_id', $branchId)->where('service_type_id', $serviceTypeId)->whereDate('queue_date', $queueDate)
            ->where('customer_phone', $phone)->whereIn('status', [Queue::WAITING, Queue::ASSIGNED, Queue::CALLED, Queue::SERVING])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['customer_phone' => 'Nomor telepon ini sudah memiliki antrian aktif untuk layanan yang sama.']);
        }
    }

    private function lockSequence(string $branchId, string $serviceTypeId, string $queueDate): QueueSequence
    {
        DB::table('queue_sequences')->insertOrIgnore(['id' => (string) Str::uuid(), 'branch_id' => $branchId, 'service_type_id' => $serviceTypeId, 'queue_date' => $queueDate, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return QueueSequence::query()->where('branch_id', $branchId)->where('service_type_id', $serviceTypeId)->whereDate('queue_date', $queueDate)->lockForUpdate()->firstOrFail();
    }

    private function resolvePriority(?string $priorityTypeId, string $branchId, ?User $actor): ?PriorityType
    {
        if (! $priorityTypeId) {
            return PriorityType::query()->where('branch_id', $branchId)->where('priority_code', 'NORMAL')->where('is_active', true)->first();
        }
        if (! $actor || ! $actor->can('queues.assign')) {
            throw ValidationException::withMessages(['priority_type_id' => 'Prioritas harus diverifikasi oleh petugas berwenang.']);
        }

        return PriorityType::query()->whereKey($priorityTypeId)->where('branch_id', $branchId)->where('is_active', true)->firstOrFail();
    }

    private function cancelLocked(Queue $queue, ?string $reason, ?User $actor): Queue
    {
        if (! in_array($queue->status, [Queue::WAITING, Queue::ASSIGNED, Queue::CALLED], true)) {
            abort(409, 'Queue tidak dapat dibatalkan pada status saat ini.');
        }
        $from = $queue->status;
        $queue->update(['status' => Queue::CANCELLED]);
        $this->releaseAssignments($queue->id);
        $this->transition($queue, $from, Queue::CANCELLED, $actor, $reason ?? 'Queue cancelled');
        QueueStatusChanged::dispatch($queue->branch_id, $this->eventPayload($queue));
        $this->audit->recordModel('queue.cancelled', $queue, $actor?->id, ['status' => $from], $this->eventPayload($queue), ['reason' => $reason]);

        return $queue->load(['serviceType', 'priorityType']);
    }

    private function transition(Queue $queue, ?string $from, string $to, ?User $actor, string $reason): void
    {
        QueueTransition::create(['id' => (string) Str::uuid(), 'branch_id' => $queue->branch_id, 'queue_id' => $queue->id, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor?->id, 'reason' => $reason, 'transitioned_at' => now()]);
    }

    private function eventPayload(Queue $queue): array
    {
        return ['queue_id' => $queue->id, 'queue_number' => $queue->queue_number, 'customer_name' => $queue->customer_name, 'service_type_id' => $queue->service_type_id, 'status' => $queue->status, 'called_at' => $queue->called_at?->toIso8601String(), 'completed_at' => $queue->completed_at?->toIso8601String()];
    }

    /** Petugas non-supervisor hanya boleh memproses antrian yang di-assign kepadanya. */
    private function authorizeScope(Queue $queue, User $actor): void
    {
        if ($actor->branch_id !== null && $actor->branch_id !== $queue->branch_id) {
            abort(403, 'Queue bukan milik cabang Anda.');
        }
        if ($actor->unit_id !== null && $queue->unit_id !== null && $actor->unit_id !== $queue->unit_id) {
            abort(403, 'Queue bukan milik unit Anda.');
        }
    }

    private function authorizeOperator(Queue $queue, User $actor): void
    {
        if ($actor->can('queues.assign')) {
            return;
        }
        $employeeId = $actor->employee?->id;
        $owns = $employeeId !== null && QueueAssignment::query()->where('queue_id', $queue->id)->where('employee_id', $employeeId)->whereNull('ended_at')->exists();
        if (! $owns) {
            abort(403, 'Antrian ini tidak dialokasikan kepada Anda.');
        }
    }

    private function logCall(Queue $queue, string $type): void
    {
        $assignment = QueueAssignment::query()->where('queue_id', $queue->id)->whereNull('ended_at')->first();
        if (! $assignment) {
            abort(409, 'Queue belum memiliki assignment aktif.');
        }
        QueueCallLog::create(['id' => (string) Str::uuid(), 'branch_id' => $queue->branch_id, 'queue_id' => $queue->id, 'employee_id' => $assignment->employee_id, 'counter_id' => $assignment->counter_id, 'call_type' => $type, 'event_id' => (string) Str::uuid(), 'called_at' => now()]);
    }

    private function callPayload(Queue $queue, array $payload): array
    {
        $assignment = QueueAssignment::query()->with('counter')->where('queue_id', $queue->id)->whereNull('ended_at')->first();

        return array_merge($payload, [
            'service_name' => $queue->serviceType?->service_name,
            'employee_id' => $assignment?->employee_id,
            'counter_id' => $assignment?->counter_id,
            'counter_code' => $assignment?->counter?->counter_code,
            'counter_name' => $assignment?->counter?->counter_name,
            'counter_type' => $assignment?->counter?->counter_type,
        ]);
    }

    private function releaseAssignments(string $queueId): void
    {
        $assignments = QueueAssignment::query()->where('queue_id', $queueId)->whereNull('ended_at')->lockForUpdate()->get();
        QueueAssignment::query()->whereIn('id', $assignments->pluck('id'))->update(['ended_at' => now(), 'updated_at' => now()]);
        foreach ($assignments as $assignment) {
            $employee = Employee::query()->whereKey($assignment->employee_id)->lockForUpdate()->first();
            if ($employee && ! $employee->activeServiceSessions()->exists()) {
                EmployeeStatusLog::create(['id' => (string) Str::orderedUuid(), 'branch_id' => $employee->branch_id, 'employee_id' => $employee->id, 'from_status' => EmployeeStatusService::BUSY, 'to_status' => EmployeeStatusService::AVAILABLE, 'reason' => 'Queue assignment released', 'heartbeat_at' => now(), 'recorded_at' => now()]);
                EmployeeStatusChanged::dispatch($employee->branch_id, ['employee_id' => $employee->id, 'status' => EmployeeStatusService::AVAILABLE], $employee->id);
            }
        }
    }

    private function startServiceSession(Queue $queue): void
    {
        $assignment = QueueAssignment::query()->where('queue_id', $queue->id)->whereNull('ended_at')->lockForUpdate()->first();
        if (! $assignment) {
            abort(409, 'Queue belum memiliki assignment aktif.');
        }
        if (! ServiceSession::query()->where('queue_id', $queue->id)->whereNull('completed_at')->exists()) {
            ServiceSession::create(['id' => (string) Str::uuid(), 'branch_id' => $queue->branch_id, 'queue_id' => $queue->id, 'employee_id' => $assignment->employee_id, 'counter_id' => $assignment->counter_id, 'started_at' => now()]);
        }
    }

    private function completeServiceSession(Queue $queue, string $outcome): void
    {
        ServiceSession::query()->where('queue_id', $queue->id)->whereNull('completed_at')->lockForUpdate()->update(['completed_at' => now(), 'outcome' => $outcome, 'updated_at' => now()]);
    }
}
