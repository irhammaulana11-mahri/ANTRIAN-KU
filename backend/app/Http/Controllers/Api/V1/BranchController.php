<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\BackendFoundationService;
use App\Services\BranchAvailabilityService;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function __construct(private readonly BackendFoundationService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Branch::class);
        $query = Branch::query()->withCount(['employees', 'counters', 'serviceTypes'])
            ->when($request->attributes->get('branch_id'), fn ($q, $branchId) => $q->whereKey($branchId));
        $this->service->listing($query, $request, ['branches.branch_code', 'branches.branch_name', 'coalesce(branches.address, \'\')'], ['branch_code', 'branch_name', 'is_active', 'created_at'], 'branch_name');

        return BranchResource::collection($query->paginate($this->service->perPage($request)));
    }

    /** Katalog publik untuk pendaftaran online, lengkap dengan status buka/tutup dan sisa kuota hari ini. */
    public function publicIndex(BranchAvailabilityService $availability)
    {
        $branches = Branch::query()->where('is_active', true)->orderBy('branch_name')->get();

        return response()->json(['data' => $branches->map(fn (Branch $branch): array => [
            'id' => $branch->id, 'branch_code' => $branch->branch_code, 'branch_name' => $branch->branch_name,
            'address' => $branch->address, 'phone' => $branch->phone, 'availability' => $availability->status($branch->id),
        ])->values()]);
    }

    public function store(BranchRequest $request)
    {
        $this->authorize('create', Branch::class);

        return (new BranchResource($this->service->createBranch($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        $this->authorize('view', $branch);

        return new BranchResource($branch);
    }

    public function update(BranchRequest $request, Branch $branch): BranchResource
    {
        $user = $request->user();
        if ($user->can('update', $branch)) {
            $data = $request->validated();
        } else {
            $this->authorize('updateProfile', $branch);
            $data = $request->safe()->only(['branch_name', 'address', 'phone']);
        }

        return new BranchResource($this->service->updateBranch($branch, $data, $user));
    }

    public function destroy(Request $request, Branch $branch)
    {
        $this->authorize('delete', $branch);
        $this->service->deleteBranch($branch, $request->user());

        return response()->noContent();
    }

    public function restore(Request $request, Branch $branch): BranchResource
    {
        $this->authorize('update', $branch);

        return new BranchResource($this->service->restoreBranch($branch, $request->user()));
    }
}
