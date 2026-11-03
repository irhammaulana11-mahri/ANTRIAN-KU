<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceTypeRequest;
use App\Http\Resources\ServiceTypeResource;
use App\Models\ServiceType;
use App\Services\BackendFoundationService;
use Illuminate\Http\Request;

class ServiceTypeController extends Controller
{
    public function __construct(private readonly BackendFoundationService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ServiceType::class);
        $user = $request->user();
        $branchId = $request->attributes->get('branch_id');
        // Layanan tanpa unit berlaku untuk semua unit, jadi Admin Unit melihat layanan umum + layanan unitnya.
        $query = ServiceType::query()->with('unit')
            ->when($branchId, fn ($q) => $q->where('service_types.branch_id', $branchId))
            ->when($user->unit_id, fn ($q) => $q->where(fn ($w) => $w->whereNull('service_types.unit_id')->orWhere('service_types.unit_id', $user->unit_id)))
            ->when(! $user->unit_id && $request->filled('unit_id'), fn ($q) => $q->where('service_types.unit_id', $request->string('unit_id')->toString()));
        $this->service->listing($query, $request, ['service_types.service_name', 'service_types.queue_prefix'], ['service_name', 'queue_prefix', 'is_active', 'created_at'], 'service_name');

        return ServiceTypeResource::collection($query->paginate($this->service->perPage($request)));
    }

    public function publicIndex(Request $request)
    {
        abort_unless($request->filled('branch_id'), 422, 'branch_id wajib diisi.');

        $unitId = $request->input('unit_id');

        return ServiceTypeResource::collection(ServiceType::query()->where('branch_id', $request->string('branch_id'))->where('is_active', true)
            ->when($unitId, fn ($q) => $q->where(fn ($w) => $w->whereNull('unit_id')->orWhere('unit_id', $unitId)))
            ->orderBy('service_name')->get());
    }

    public function store(ServiceTypeRequest $request)
    {
        $this->authorize('create', ServiceType::class);
        $data = $request->validated();
        $branchId = $this->service->branchId($request, $data['branch_id'] ?? null);
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');

        return (new ServiceTypeResource($this->service->createServiceType($data, $branchId, $request->user())))->response()->setStatusCode(201);
    }

    public function show(ServiceType $serviceType): ServiceTypeResource
    {
        $this->authorize('view', $serviceType);

        return new ServiceTypeResource($serviceType);
    }

    public function update(ServiceTypeRequest $request, ServiceType $serviceType): ServiceTypeResource
    {
        $this->authorize('update', $serviceType);

        return new ServiceTypeResource($this->service->updateServiceType($serviceType, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, ServiceType $serviceType)
    {
        $this->authorize('delete', $serviceType);
        $this->service->deleteServiceType($serviceType, $request->user());

        return response()->noContent();
    }

    public function restore(Request $request, ServiceType $serviceType): ServiceTypeResource
    {
        $this->authorize('update', $serviceType);

        return new ServiceTypeResource($this->service->restoreServiceType($serviceType, $request->user()));
    }
}
