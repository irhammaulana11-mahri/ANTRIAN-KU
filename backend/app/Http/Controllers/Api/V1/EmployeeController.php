<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompetencyRequest;
use App\Http\Requests\EmployeeCountersRequest;
use App\Http\Requests\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\BackendFoundationService;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(private readonly BackendFoundationService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Employee::class);
        $actor = $request->user();
        $query = $this->service->scope(new Employee, $actor, $request->attributes->get('branch_id'))->with(['user.roles', 'serviceTypes', 'counters', 'unit']);

        // Tampilkan hanya petugas di bawah hirarki aktor: Admin Unit → CS/Teller saja; Admin Cabang → Admin Unit + CS/Teller.
        if ($actor->unit_id !== null) {
            // Admin Unit: hanya tampilkan CS dan Teller
            $query->whereHas('user.roles', fn ($r) => $r->whereIn('name', ['Customer Service', 'Teller']));
        } elseif ($actor->branch_id !== null) {
            // Admin Cabang: tampilkan Admin Unit, CS, dan Teller
            $query->whereHas('user.roles', fn ($r) => $r->whereIn('name', ['Admin Unit', 'Customer Service', 'Teller']));
        }
        // Super Admin (branch_id===null): tampilkan semua

        $query->when($request->filled('role'), fn ($q) => $q->whereHas('user.roles', fn ($roles) => $roles->where('name', $request->string('role')->toString())));
        $this->service->listing(
            $query, $request, ['employees.employee_code', 'employees.employee_name'], ['employee_code', 'employee_name', 'is_active', 'created_at'], 'employee_name',
            fn ($nested, string $like) => $nested->whereHas('user', fn ($user) => $user->whereRaw("lower(email) like ? escape '!'", [$like])),
        );

        return EmployeeResource::collection($query->paginate($this->service->perPage($request)));
    }

    public function store(EmployeeRequest $request)
    {
        $this->authorize('create', Employee::class);
        $data = $request->validated();
        $branchId = $this->service->branchId($request, $data['branch_id'] ?? null);
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');
        $employee = $this->service->createEmployee($data, $branchId, $request->user())->load(['user.roles', 'serviceTypes', 'counters']);

        return (new EmployeeResource($employee))->response()->setStatusCode(201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        return new EmployeeResource($employee->load(['user.roles', 'serviceTypes', 'counters']));
    }

    public function update(EmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $this->authorize('update', $employee);

        return new EmployeeResource($this->service->updateEmployee($employee, $request->validated(), $request->user())->load(['user.roles', 'serviceTypes', 'counters']));
    }

    public function destroy(Request $request, Employee $employee)
    {
        $this->authorize('delete', $employee);
        $this->service->deleteEmployee($employee, $request->user());

        return response()->noContent();
    }

    public function competencies(CompetencyRequest $request, Employee $employee): EmployeeResource
    {
        $this->authorize('update', $employee);

        return new EmployeeResource($this->service->syncCompetencies($employee, $request->validated('service_type_ids'))->load('counters'));
    }

    public function counters(EmployeeCountersRequest $request, Employee $employee): EmployeeResource
    {
        $this->authorize('update', $employee);

        return new EmployeeResource($this->service->syncCounters($employee, $request->validated('counter_ids')));
    }

    public function restore(Request $request, Employee $employee): EmployeeResource
    {
        $this->authorize('update', $employee);

        return new EmployeeResource($this->service->restoreEmployee($employee, $request->user()));
    }
}
