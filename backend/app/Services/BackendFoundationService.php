<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Employee;
use App\Models\EmployeeStatusLog;
use App\Models\Queue;
use App\Models\QueueAssignment;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackendFoundationService
{
    private const ACTIVE_QUEUE_STATUSES = [Queue::WAITING, Queue::ASSIGNED, Queue::CALLED, Queue::SERVING];

    public function __construct(
        private readonly BranchAuthorizationService $branches,
        private readonly AuditLogService $audit,
        private readonly EmployeeStatusService $statuses,
    ) {}

    public function branchId(Request $request, ?string $explicit = null): ?string
    {
        return $this->branches->resolve($request->user(), $explicit ?? $request->input('branch_id'));
    }

    public function scope(Model $model, User $user, ?string $branchId = null, ?string $unitId = null)
    {
        $query = $model->newQuery();
        $resolvedBranch = $this->branches->resolve($user, $branchId);
        $resolvedUnit   = $this->branches->resolveUnit($user, $unitId);
        $table = $model->getTable();

        // Admin Unit: filter ke unit_id; selain itu (Super Admin, Admin Cabang): filter branch_id
        if ($resolvedUnit !== null && \Illuminate\Support\Facades\Schema::hasColumn($table, 'unit_id')) {
            $query->where("{$table}.unit_id", $resolvedUnit);
        } elseif ($resolvedBranch !== null) {
            $query->where("{$table}.branch_id", $resolvedBranch);
        }

        return $query;
    }

    /**
     * Pencarian (q), filter is_active, dan sorting server-side dengan whitelist kolom.
     *
     * @param  list<string>  $searchable  kolom (boleh dengan prefix tabel) untuk pencarian teks
     * @param  list<string>  $sortable  kolom yang boleh dipakai sort
     * @param  (callable(Builder, string): void)|null  $extraSearch  kondisi OR tambahan, mis. pencarian lewat relasi
     */
    public function listing(Builder $query, Request $request, array $searchable, array $sortable, string $defaultSort, ?callable $extraSearch = null): Builder
    {
        $term = trim((string) $request->input('q'));
        if ($term !== '') {
            // Karakter escape "!" (bukan backslash): driver PDO PostgreSQL salah menafsirkan '\' sehingga placeholder ? tidak terbaca.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
            $query->where(function (Builder $group) use ($searchable, $like, $extraSearch): void {
                foreach ($searchable as $column) {
                    $group->orWhereRaw("lower({$column}) like ? escape '!'", [$like]);
                }
                if ($extraSearch) {
                    $group->orWhere(fn (Builder $nested) => $extraSearch($nested, $like));
                }
            });
        }
        if ($request->boolean('deleted') && in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $query->onlyTrashed(); // tampilan "Dihapus" untuk fitur pulihkan
        } elseif ($request->filled('is_active')) {
            $query->where($query->getModel()->getTable().'.is_active', $request->boolean('is_active'));
        }
        $sort = in_array($request->input('sort'), $sortable, true) ? $request->input('sort') : $defaultSort;
        $direction = strtolower((string) $request->input('direction')) === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($query->getModel()->getTable().'.'.$sort, $direction)->orderBy($query->getModel()->getTable().'.id');
    }

    public function perPage(Request $request): int
    {
        return min(100, max(1, $request->integer('per_page', 15)));
    }

    // ---------------------------------------------------------------- Cabang

    public function createBranch(array $data, User $actor): Branch
    {
        return DB::transaction(function () use ($data, $actor): Branch {
            $branch = Branch::create(array_merge(['id' => (string) Str::uuid()], $data));
            $now = now();
            // Default agar cabang baru langsung dapat dipakai: pengaturan antrian dan kategori prioritas standar.
            DB::table('queue_settings')->insert(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'normal_fairness_quota' => 3, 'stale_heartbeat_seconds' => 90, 'voice_rate' => 1.00, 'voice_volume' => 1.00, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            foreach ([['NORMAL', 'Normal', 0], ['PRIORITY', 'Prioritas', 20], ['URGENT', 'Mendesak', 50]] as [$code, $name, $weight]) {
                DB::table('priority_types')->insert(['id' => (string) Str::uuid(), 'branch_id' => $branch->id, 'priority_code' => $code, 'priority_name' => $name, 'weight' => $weight, 'aging_rate' => 1, 'aging_interval_seconds' => 300, 'verification_required' => $code !== 'NORMAL', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            $this->audit->recordModel('branch.created', $branch, $actor->id, null, $branch->only(['branch_code', 'branch_name']));

            return $branch;
        });
    }

    public function updateBranch(Branch $branch, array $data, User $actor): Branch
    {
        if ($this->isBeingDeactivated($branch, $data)) {
            $this->assertNoActiveQueues(['branch_id' => $branch->id], 'Cabang masih memiliki antrian aktif.');
        }
        $before = $branch->only(['branch_code', 'branch_name', 'address', 'phone', 'is_active']);
        $branch->update($data);
        $this->audit->recordModel('branch.updated', $branch, $actor->id, $before, $branch->only(array_keys($before)));

        return $branch->refresh();
    }

    public function deleteBranch(Branch $branch, User $actor): void
    {
        $this->assertNoActiveQueues(['branch_id' => $branch->id], 'Cabang masih memiliki antrian aktif.');
        if ($branch->employees()->exists() || $branch->counters()->exists() || $branch->serviceTypes()->exists()) {
            abort(409, 'Cabang masih memiliki petugas, loket, atau layanan. Hapus atau pindahkan data tersebut terlebih dahulu, atau nonaktifkan cabang.');
        }
        $branch->delete();
        $this->audit->recordModel('branch.deleted', $branch, $actor->id, $branch->only(['branch_code', 'branch_name']));
    }

    // ---------------------------------------------------------------- Petugas

    public function createEmployee(array $data, string $branchId, User $actor): Employee
    {
        return DB::transaction(function () use ($data, $branchId, $actor): Employee {
            $userId = $data['user_id'] ?? null;

            $unitId = ($data['unit_id'] ?? null) ?: null;
            // Admin Unit membuat pegawai → otomatis terikat ke unitnya sendiri
            if ($actor->unit_id !== null) {
                $unitId = $actor->unit_id;
            }

            if ($userId === null) {
                $user = User::create(['id' => (string) Str::uuid(), 'branch_id' => $branchId, 'unit_id' => $unitId, 'name' => $data['employee_name'], 'email' => $data['email'], 'password' => $data['password']]);
                $user->assignRole($data['role_name']);
                $userId = $user->id;
            } else {
                $user = User::query()->whereKey($userId)->where('branch_id', $branchId)->first();
                if (! $user) {
                    abort(422, 'Akun pengguna harus berasal dari cabang yang sama.');
                }
                if (Employee::withTrashed()->where('user_id', $userId)->exists()) {
                    abort(422, 'Akun pengguna sudah terhubung dengan petugas lain.');
                }
                if (! empty($data['role_name'])) {
                    $user->syncRoles([$data['role_name']]);
                }
                if ($unitId !== null) {
                    $user->update(['unit_id' => $unitId]);
                }
            }

            $employeeCode = $data['employee_code'] ?? null;
            if (empty($employeeCode)) {
                // Auto-generate: 3 huruf awal nama + 4 karakter acak, huruf besar
                $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($data['employee_name'], 0, 3)));
                $employeeCode = ($prefix ?: 'EMP') . strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 4));
            }
            $employee = Employee::create(['id' => (string) Str::uuid(), 'branch_id' => $branchId, 'unit_id' => $unitId, 'user_id' => $userId, 'employee_code' => $employeeCode, 'employee_name' => $data['employee_name'], 'is_active' => $data['is_active'] ?? true]);
            $this->audit->recordModel('employee.created', $employee, $actor->id, null, ['employee_code' => $employee->employee_code, 'role' => $data['role_name'] ?? null, 'unit_id' => $unitId]);

            return $employee;
        });
    }

    public function updateEmployee(Employee $employee, array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actor): Employee {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $user = $locked->user;
            $before = ['employee_code' => $locked->employee_code, 'employee_name' => $locked->employee_name, 'is_active' => $locked->is_active, 'email' => $user?->email, 'role' => $user?->getRoleNames()->first()];

            $deactivating = $this->isBeingDeactivated($locked, $data);
            if ($deactivating) {
                $this->assertEmployeeCanLeave($locked, $actor, 'dinonaktifkan');
            }
            $fields = Arr::only($data, ['employee_code', 'employee_name', 'is_active', 'unit_id']);
            if (array_key_exists('unit_id', $fields)) {
                $fields['unit_id'] = $fields['unit_id'] ?: null;
            }
            if ($actor->unit_id !== null) {
                $fields['unit_id'] = $actor->unit_id;
            }
            $locked->update($fields);

            if ($user) {
                $userData = array_filter(['name' => $data['employee_name'] ?? null, 'email' => $data['email'] ?? null, 'password' => $data['password'] ?? null]);
                if ($userData !== []) {
                    $user->update($userData);
                }
                if ($user->unit_id !== $locked->unit_id) {
                    $user->update(['unit_id' => $locked->unit_id]);
                }
                if (! empty($data['role_name']) && $data['role_name'] !== $before['role']) {
                    if ($user->id === $actor->id) {
                        abort(409, 'Anda tidak dapat mengubah role akun sendiri.');
                    }
                    $user->syncRoles([$data['role_name']]);
                }
                if (! empty($data['password']) || $deactivating) {
                    $user->tokens()->delete(); // password direset / akun dinonaktifkan → semua sesi login berakhir
                }
            }
            if ($deactivating) {
                $this->closeStatus($locked);
            }
            $after = ['employee_code' => $locked->employee_code, 'employee_name' => $locked->employee_name, 'is_active' => $locked->is_active, 'email' => $user?->refresh()->email, 'role' => $user?->getRoleNames()->first()];
            $this->audit->recordModel('employee.updated', $locked, $actor->id, $before, $after, ['password_reset' => ! empty($data['password'])]);

            return $locked->refresh();
        });
    }

    public function deleteEmployee(Employee $employee, User $actor): void
    {
        DB::transaction(function () use ($employee, $actor): void {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->assertEmployeeCanLeave($locked, $actor, 'dihapus');
            $locked->user?->tokens()->delete();
            $this->closeStatus($locked);
            $locked->delete();
            $this->audit->recordModel('employee.deleted', $locked, $actor->id, ['employee_code' => $locked->employee_code, 'employee_name' => $locked->employee_name]);
        });
    }

    public function syncCompetencies(Employee $employee, array $serviceTypeIds): Employee
    {
        return DB::transaction(function () use ($employee, $serviceTypeIds): Employee {
            $validIds = ServiceType::query()->where('branch_id', $employee->branch_id)->whereIn('id', $serviceTypeIds)->pluck('id')->all();
            if (count($validIds) !== count(array_unique($serviceTypeIds))) {
                abort(422, 'Semua service type harus berasal dari cabang employee.');
            }
            $employee->serviceTypes()->sync($validIds);

            return $employee->load('serviceTypes');
        });
    }

    public function syncCounters(Employee $employee, array $counterIds): Employee
    {
        return DB::transaction(function () use ($employee, $counterIds): Employee {
            $ids = array_values(array_unique($counterIds));
            $validIds = Counter::query()->where('branch_id', $employee->branch_id)->whereIn('id', $ids)->pluck('id')->all();
            if (count($validIds) !== count($ids)) {
                abort(422, 'Semua loket harus berasal dari cabang petugas.');
            }
            $employee->counters()->sync($validIds);

            return $employee->load(['serviceTypes', 'counters']);
        });
    }

    // ---------------------------------------------------------------- Loket

    public function createCounter(array $data, string $branchId, User $actor): Counter
    {
        // Admin Unit membuat loket → otomatis terikat ke unitnya
        if ($actor->unit_id !== null) {
            $data['unit_id'] = $actor->unit_id;
        }
        $counter = Counter::create(array_merge(['id' => (string) Str::uuid()], $data, ['branch_id' => $branchId]));
        $this->audit->recordModel('counter.created', $counter, $actor->id, null, $counter->only(['counter_code', 'counter_name', 'counter_type']));

        return $counter;
    }

    public function updateCounter(Counter $counter, array $data, User $actor): Counter
    {
        unset($data['branch_id']);
        if ($actor->unit_id !== null) {
            $data['unit_id'] = $actor->unit_id;
        }
        if ($this->isBeingDeactivated($counter, $data)) {
            $this->assertCounterFree($counter, 'dinonaktifkan');
        }
        $before = $counter->only(['counter_code', 'counter_name', 'counter_type', 'is_active']);
        $counter->update($data);
        $this->audit->recordModel('counter.updated', $counter, $actor->id, $before, $counter->only(array_keys($before)));

        return $counter->refresh();
    }

    public function deleteCounter(Counter $counter, User $actor): void
    {
        DB::transaction(function () use ($counter, $actor): void {
            $this->assertCounterFree($counter, 'dihapus');
            $counter->employees()->detach(); // loket yang dihapus tidak boleh tersisa di daftar loket petugas
            $counter->delete();
            $this->audit->recordModel('counter.deleted', $counter, $actor->id, $counter->only(['counter_code', 'counter_name']));
        });
    }

    // ---------------------------------------------------------------- Layanan

    public function createServiceType(array $data, string $branchId, User $actor): ServiceType
    {
        $service = ServiceType::create(array_merge(['id' => (string) Str::uuid()], $data, ['branch_id' => $branchId]));
        $this->audit->recordModel('service_type.created', $service, $actor->id, null, $service->only(['service_name', 'queue_prefix']));

        return $service;
    }

    public function updateServiceType(ServiceType $serviceType, array $data, User $actor): ServiceType
    {
        unset($data['branch_id']);
        if ($this->isBeingDeactivated($serviceType, $data)) {
            $this->assertNoActiveQueues(['service_type_id' => $serviceType->id], 'Layanan masih memiliki antrian aktif. Selesaikan atau batalkan antrian tersebut terlebih dahulu.');
        }
        $before = $serviceType->only(['service_name', 'queue_prefix', 'priority_enabled', 'is_active']);
        $serviceType->update($data);
        $this->audit->recordModel('service_type.updated', $serviceType, $actor->id, $before, $serviceType->only(array_keys($before)));

        return $serviceType->refresh();
    }

    public function deleteServiceType(ServiceType $serviceType, User $actor): void
    {
        DB::transaction(function () use ($serviceType, $actor): void {
            $this->assertNoActiveQueues(['service_type_id' => $serviceType->id], 'Layanan masih memiliki antrian aktif. Selesaikan atau batalkan antrian tersebut terlebih dahulu.');
            $serviceType->employees()->detach(); // petugas tidak lagi berkompetensi pada layanan yang dihapus
            $serviceType->delete();
            $this->audit->recordModel('service_type.deleted', $serviceType, $actor->id, $serviceType->only(['service_name']));
        });
    }

    // ---------------------------------------------------------------- Pulihkan

    public function restoreBranch(Branch $branch, User $actor): Branch
    {
        $this->assertTrashed($branch);
        $branch->restore();
        $this->audit->recordModel('branch.restored', $branch, $actor->id, null, $branch->only(['branch_code', 'branch_name']));

        return $branch->refresh();
    }

    public function restoreEmployee(Employee $employee, User $actor): Employee
    {
        $this->assertTrashed($employee);
        $this->assertBranchAlive($employee);
        $employee->restore();
        $this->audit->recordModel('employee.restored', $employee, $actor->id, null, $employee->only(['employee_code', 'employee_name']));

        return $employee->refresh();
    }

    public function restoreCounter(Counter $counter, User $actor): Counter
    {
        $this->assertTrashed($counter);
        $this->assertBranchAlive($counter);
        $counter->restore();
        $this->audit->recordModel('counter.restored', $counter, $actor->id, null, $counter->only(['counter_code', 'counter_name']));

        return $counter->refresh();
    }

    public function restoreServiceType(ServiceType $serviceType, User $actor): ServiceType
    {
        $this->assertTrashed($serviceType);
        $this->assertBranchAlive($serviceType);
        $serviceType->restore();
        $this->audit->recordModel('service_type.restored', $serviceType, $actor->id, null, $serviceType->only(['service_name']));

        return $serviceType->refresh();
    }

    private function assertTrashed(Model $model): void
    {
        if (! $model->trashed()) {
            abort(409, 'Data ini tidak berstatus dihapus.');
        }
    }

    private function assertBranchAlive(Model $model): void
    {
        if (Branch::withTrashed()->whereKey($model->branch_id)->first()?->trashed() !== false) {
            abort(409, 'Cabang induk data ini sudah dihapus. Pulihkan cabangnya terlebih dahulu.');
        }
    }

    // ---------------------------------------------------------------- Pengaman

    private function isBeingDeactivated(Model $model, array $data): bool
    {
        return array_key_exists('is_active', $data) && ! $data['is_active'] && $model->is_active;
    }

    private function assertNoActiveQueues(array $where, string $message): void
    {
        if (Queue::query()->where($where)->whereIn('status', self::ACTIVE_QUEUE_STATUSES)->exists()) {
            abort(409, $message);
        }
    }

    private function assertCounterFree(Counter $counter, string $verb): void
    {
        if (QueueAssignment::query()->where('counter_id', $counter->id)->whereNull('ended_at')->exists()) {
            abort(409, "Loket sedang digunakan untuk melayani antrian dan tidak dapat {$verb}.");
        }
    }

    private function assertEmployeeCanLeave(Employee $employee, User $actor, string $verb): void
    {
        if ($employee->user_id !== null && $employee->user_id === $actor->id) {
            abort(409, "Akun Anda sendiri tidak dapat {$verb}.");
        }
        if ($employee->activeAssignments()->exists() || $employee->activeServiceSessions()->exists()) {
            abort(409, "Petugas sedang memiliki antrian aktif dan tidak dapat {$verb}. Selesaikan atau pindahkan antrian tersebut terlebih dahulu.");
        }
    }

    /** Petugas yang keluar dari sistem harus berstatus CLOSED agar tidak pernah masuk kandidat distribusi. */
    private function closeStatus(Employee $employee): void
    {
        $current = $this->statuses->current($employee);
        if ($current !== EmployeeStatusService::CLOSED) {
            EmployeeStatusLog::create(['id' => (string) Str::orderedUuid(), 'branch_id' => $employee->branch_id, 'employee_id' => $employee->id, 'from_status' => $current, 'to_status' => EmployeeStatusService::CLOSED, 'reason' => 'Petugas dinonaktifkan/dihapus', 'heartbeat_at' => null, 'recorded_at' => now()]);
        }
    }
}
