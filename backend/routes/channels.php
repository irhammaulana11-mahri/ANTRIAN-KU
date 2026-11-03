<?php

use App\Models\DisplayDevice;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// $user bisa berupa User (staf) atau DisplayDevice (TV) — keduanya hanya boleh masuk channel cabangnya sendiri.
Broadcast::channel('branch.{branchId}', function ($user, string $branchId): bool {
    if ($user instanceof DisplayDevice) {
        // Perangkat yang terkunci ke unit tidak boleh masuk channel cabang (berisi data semua unit).
        return ! $user->isRevoked() && $user->device_type === DisplayDevice::DISPLAY && $user->tokenCan('display') && $user->branch_id === $branchId && $user->unit_id === null;
    }

    return $user instanceof User && ($user->branch_id === null || $user->branch_id === $branchId);
});

Broadcast::channel('branch.{branchId}.unit.{unitId}', function ($user, string $branchId, string $unitId): bool {
    if ($user instanceof DisplayDevice) {
        return ! $user->isRevoked() && $user->device_type === DisplayDevice::DISPLAY && $user->tokenCan('display') && $user->branch_id === $branchId && $user->unit_id === $unitId;
    }

    return $user instanceof User && ($user->branch_id === null || $user->branch_id === $branchId) && ($user->unit_id === null || $user->unit_id === $unitId);
});

Broadcast::channel('employee.{employeeId}', function ($user, string $employeeId): bool {
    if (! $user instanceof User) {
        return false;
    }
    $employee = Employee::query()->find($employeeId);

    return $employee !== null && ($user->branch_id === null || $user->branch_id === $employee->branch_id) && ($employee->user_id === $user->id || $user->can('queues.view'));
});
