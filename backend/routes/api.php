<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CounterController;
use App\Http\Controllers\Api\V1\DisplayController;
use App\Http\Controllers\Api\V1\DisplayDeviceController;
use App\Http\Controllers\Api\V1\DisplaySettingsController;
use App\Http\Controllers\Api\V1\DistributionController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeWorkspaceController;
use App\Http\Controllers\Api\V1\EmployeeStatusController;
use App\Http\Controllers\Api\V1\KioskController;
use App\Http\Controllers\Api\V1\PriorityTypeController;
use App\Http\Controllers\Api\V1\QueueController;
use App\Http\Controllers\Api\V1\QueueSettingsController;
use App\Http\Controllers\Api\V1\ReportingController;
use App\Http\Controllers\Api\V1\ServiceTypeController;
use App\Http\Controllers\Api\V1\SupervisorController;
use App\Http\Controllers\Api\V1\UnitController;
use Illuminate\Support\Facades\Route;

Route::get('docs', [ApiDocsController::class, 'ui']);
Route::get('docs/openapi.yaml', [ApiDocsController::class, 'spec']);

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:auth.login']);
    Route::get('catalog/branches', [BranchController::class, 'publicIndex']);
    Route::get('catalog/service-types', [ServiceTypeController::class, 'publicIndex']);
    Route::post('queues/online', [QueueController::class, 'online'])->middleware('throttle:queue.register');
    Route::get('queues/track/{token}', [QueueController::class, 'track']);
    Route::post('queues/cancel-by-token', [QueueController::class, 'cancelByToken']);

    Route::post('display/activate', [DisplayController::class, 'activate'])->middleware('throttle:display.activate');
    Route::middleware(['auth:sanctum', 'device.type:DISPLAY'])->get('display/snapshot', [DisplayController::class, 'snapshot']);
    Route::middleware(['auth:sanctum', 'device.type:DISPLAY,KIOSK'])->post('display/refresh', [DisplayController::class, 'refresh']);
    Route::middleware(['auth:sanctum', 'device.type:KIOSK'])->prefix('kiosk')->group(function (): void {
        Route::get('config', [KioskController::class, 'config']);
        Route::post('queues', [KioskController::class, 'store'])->middleware('throttle:kiosk');
    });

    Route::middleware(['auth:sanctum', 'branch.context'])->group(function (): void {
        Route::get('display-devices', [DisplayDeviceController::class, 'index']);
        Route::post('display-devices', [DisplayDeviceController::class, 'store']);
        Route::post('display-devices/{device}/rotate', [DisplayDeviceController::class, 'rotate']);
        Route::post('display-devices/{device}/revoke', [DisplayDeviceController::class, 'revoke']);
        Route::get('priority-types', [PriorityTypeController::class, 'index']);
        Route::get('settings/queue', [QueueSettingsController::class, 'show']);
        Route::put('settings/queue', [QueueSettingsController::class, 'update']);
        Route::post('settings/holidays', [QueueSettingsController::class, 'storeHoliday']);
        Route::delete('settings/holidays/{holiday}', [QueueSettingsController::class, 'destroyHoliday']);
        Route::get('settings/display', [DisplaySettingsController::class, 'show']);
        Route::put('settings/display', [DisplaySettingsController::class, 'update']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::put('auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('auth/password', [AuthController::class, 'updatePassword']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);

        Route::post('branches/{branch}/restore', [BranchController::class, 'restore'])->withTrashed();
        Route::post('units/{unit}/restore', [UnitController::class, 'restore'])->withTrashed();
        Route::post('employees/{employee}/restore', [EmployeeController::class, 'restore'])->withTrashed();
        Route::post('counters/{counter}/restore', [CounterController::class, 'restore'])->withTrashed();
        Route::post('service-types/{service_type}/restore', [ServiceTypeController::class, 'restore'])->withTrashed();
        Route::apiResource('branches', BranchController::class);
        Route::get('units/dropdown', [UnitController::class, 'dropdown']);
        Route::apiResource('units', UnitController::class);
        Route::apiResource('employees', EmployeeController::class);
        Route::put('employees/{employee}/competencies', [EmployeeController::class, 'competencies']);
        Route::put('employees/{employee}/counters', [EmployeeController::class, 'counters']);
        Route::get('employee/workspace', [EmployeeWorkspaceController::class, 'show']);
        Route::post('employee/status', [EmployeeWorkspaceController::class, 'status']);
        Route::post('employee/heartbeat', [EmployeeWorkspaceController::class, 'heartbeat']);
        Route::post('service/{queue}/{action}', [EmployeeWorkspaceController::class, 'act'])->whereIn('action', ['call', 'recall', 'start', 'complete', 'skip', 'no-show']);
        Route::post('employees/{employee}/status', [EmployeeStatusController::class, 'update']);
        Route::post('employees/{employee}/heartbeat', [EmployeeStatusController::class, 'heartbeat']);
        Route::apiResource('counters', CounterController::class);
        Route::apiResource('service-types', ServiceTypeController::class);
        // Walk-in oleh staf (loket depan/supervisor). Tidak lagi publik: jam operasional & kuota online tidak boleh bisa dilewati dari luar.
        Route::post('queues/walk-in', [QueueController::class, 'walkIn'])->middleware('throttle:queue.register');
        Route::get('queues/history', [QueueController::class, 'history']);
        Route::post('queues/{queue}/cancel', [QueueController::class, 'cancel']);
        Route::post('queues/{queue}/verify-priority', [QueueController::class, 'verifyPriority']);
        Route::post('queues/{queue}/transfer', [QueueController::class, 'transfer']);
        Route::patch('queues/{queue}/status', [QueueController::class, 'changeStatus']);
        Route::get('supervisor/board', [SupervisorController::class, 'board']);
        Route::post('distribution/next', [DistributionController::class, 'next']);
        Route::post('queues/{queue}/assign', [DistributionController::class, 'manual']);
        Route::get('reports/summary', [ReportingController::class, 'summary']);
        Route::get('reports/export', [ReportingController::class, 'export']);
        Route::get('audit-logs', [ReportingController::class, 'audit']);
    });
});
