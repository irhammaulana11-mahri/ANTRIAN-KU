<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeStatusRequest;
use App\Http\Requests\HeartbeatRequest;
use App\Models\Employee;
use App\Services\EmployeeStatusService;
use Illuminate\Http\JsonResponse;

class EmployeeStatusController extends Controller
{
    public function __construct(private readonly EmployeeStatusService $statuses) {}

    public function update(EmployeeStatusRequest $request, Employee $employee): JsonResponse
    {
        $log = $this->statuses->setStatus($employee, $request->string('status')->toString(), $request->user(), $request->input('reason'));

        return response()->json(['data' => ['employee_id' => $log->employee_id, 'status' => $log->to_status, 'recorded_at' => $log->recorded_at], 'message' => 'Status petugas diperbarui.']);
    }

    public function heartbeat(HeartbeatRequest $request, Employee $employee): JsonResponse
    {
        $log = $this->statuses->heartbeat($employee, $request->user());

        return response()->json(['data' => ['employee_id' => $log->employee_id, 'status' => $log->to_status, 'heartbeat_at' => $log->heartbeat_at], 'message' => 'Heartbeat diterima.']);
    }
}
