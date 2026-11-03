<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use App\Services\BackendFoundationService;
use App\Services\BranchServiceMode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UnitController extends Controller
{
    public function __construct(private readonly BackendFoundationService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Unit::class);
        $user = $request->user();

        $query = Unit::query()->withCount(['employees', 'counters'])
            // Super Admin: semua; Admin Cabang: hanya cabangnya; Admin Unit: hanya unitnya
            ->when($user->branch_id !== null, fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($user->unit_id !== null, fn ($q) => $q->whereKey($user->unit_id));

        $this->service->listing(
            $query, $request,
            ['units.unit_code', 'units.unit_name'],
            ['unit_code', 'unit_name', 'is_active', 'created_at'],
            'unit_name',
        );

        return UnitResource::collection($query->paginate($this->service->perPage($request)));
    }

    /** Daftar unit yang tersedia untuk pilihan dropdown (misalnya saat buat pegawai/loket). */
    public function dropdown(Request $request)
    {
        $this->authorize('viewAny', Unit::class);
        $user = $request->user();

        $units = Unit::query()
            ->where('is_active', true)
            ->when($user->branch_id !== null, fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($user->unit_id !== null, fn ($q) => $q->whereKey($user->unit_id))
            ->when($request->filled('branch_id') && $user->branch_id === null, fn ($q) => $q->where('branch_id', $request->string('branch_id')->toString()))
            ->orderBy('unit_name')
            ->get(['id', 'branch_id', 'unit_code', 'unit_name']);

        // Cabang yang dituju (Admin Cabang/Unit: cabangnya; Super Admin: dari parameter) menentukan apakah unit boleh dikosongkan.
        $branchId = $user->branch_id ?? $request->input('branch_id');

        return response()->json(['data' => $units, 'meta' => ['branch_serves_directly' => app(\App\Services\BranchServiceMode::class)->servesDirectly($branchId)]]);
    }

    public function store(UnitRequest $request)
    {
        $this->authorize('create', Unit::class);
        $data = $request->validated();
        $branchId = $this->service->branchId($request, $data['branch_id'] ?? null);
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');

        $unit = Unit::create([
            'id'        => (string) Str::uuid(),
            'branch_id' => $branchId,
            'unit_code' => $data['unit_code'],
            'unit_name' => $data['unit_name'],
            'address'   => $data['address'] ?? null,
            'phone'     => $data['phone'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return (new UnitResource($unit->loadCount(['employees', 'counters'])))->response()->setStatusCode(201);
    }

    public function show(Unit $unit): UnitResource
    {
        $this->authorize('view', $unit);

        return new UnitResource($unit->loadCount(['employees', 'counters']));
    }

    public function update(UnitRequest $request, Unit $unit): UnitResource
    {
        $this->authorize('update', $unit);
        $unit->update($request->safe()->except('branch_id'));

        return new UnitResource($unit->loadCount(['employees', 'counters']));
    }

    public function destroy(Unit $unit)
    {
        $this->authorize('delete', $unit);
        abort_if(
            $unit->employees()->exists() || $unit->counters()->exists(),
            422,
            'Unit masih memiliki pegawai atau loket aktif. Pindahkan atau hapus terlebih dahulu.',
        );
        $unit->delete();

        return response()->noContent();
    }

    public function restore(Unit $unit): UnitResource
    {
        $this->authorize('update', $unit);
        $unit->restore();

        return new UnitResource($unit->loadCount(['employees', 'counters']));
    }
}
