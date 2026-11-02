<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DistributionNextRequest;
use App\Http\Requests\ManualAssignmentRequest;
use App\Http\Resources\QueueAssignmentResource;
use App\Models\Employee;
use App\Models\Queue;
use App\Services\QueueDistributionEngine;
use Illuminate\Http\JsonResponse;

class DistributionController extends Controller
{
    public function __construct(private readonly QueueDistributionEngine $engine) {}

    public function next(DistributionNextRequest $request): JsonResponse
    {
        $branchId = $request->attributes->get('branch_id');
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');
        $assignment = $this->engine->assignNext($branchId, $request->string('service_type_id')->toString(), $request->user());

        return response()->json(['data' => $assignment ? new QueueAssignmentResource($assignment) : null, 'message' => $assignment ? 'Queue berhasil di-assign.' : 'Tidak ada queue atau petugas yang eligible.']);
    }

    public function manual(ManualAssignmentRequest $request, Queue $queue): JsonResponse
    {
        $employee = Employee::query()->findOrFail($request->string('employee_id')->toString());
        $assignment = $this->engine->manualAssign($queue, $employee, $request->string('counter_id')->toString(), $request->user(), $request->string('reason')->toString());

        return response()->json(['data' => new QueueAssignmentResource($assignment), 'message' => 'Assignment manual berhasil.'], 201);
    }
}
