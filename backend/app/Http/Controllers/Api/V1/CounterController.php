<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CounterRequest;
use App\Http\Resources\CounterResource;
use App\Models\Counter;
use App\Services\BackendFoundationService;
use Illuminate\Http\Request;

class CounterController extends Controller
{
    public function __construct(private readonly BackendFoundationService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Counter::class);
        $query = $this->service->scope(new Counter, $request->user(), $request->attributes->get('branch_id'));
        $query->when($request->filled('counter_type'), fn ($q) => $q->where('counters.counter_type', $request->string('counter_type')->toString()));
        $this->service->listing($query, $request, ['counters.counter_code', 'counters.counter_name'], ['counter_code', 'counter_name', 'counter_type', 'is_active', 'created_at'], 'counter_code');

        return CounterResource::collection($query->paginate($this->service->perPage($request)));
    }

    public function store(CounterRequest $request)
    {
        $this->authorize('create', Counter::class);
        $data = $request->validated();
        $branchId = $this->service->branchId($request, $data['branch_id'] ?? null);
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');

        return (new CounterResource($this->service->createCounter($data, $branchId, $request->user())))->response()->setStatusCode(201);
    }

    public function show(Counter $counter): CounterResource
    {
        $this->authorize('view', $counter);

        return new CounterResource($counter);
    }

    public function update(CounterRequest $request, Counter $counter): CounterResource
    {
        $this->authorize('update', $counter);

        return new CounterResource($this->service->updateCounter($counter, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, Counter $counter)
    {
        $this->authorize('delete', $counter);
        $this->service->deleteCounter($counter, $request->user());

        return response()->noContent();
    }

    public function restore(Request $request, Counter $counter): CounterResource
    {
        $this->authorize('update', $counter);

        return new CounterResource($this->service->restoreCounter($counter, $request->user()));
    }
}
