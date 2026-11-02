<?php

use App\Models\DisplayDevice;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// $user bisa berupa User (staf) atau DisplayDevice (TV) — keduanya hanya boleh masuk channel cabangnya sendiri.
Broadcast::channel('branch.{branchId}', function ($user, string $branchId): bool {
    if ($user instanceof DisplayDevice) {
        return ! $user->isRevoked() && $user->device_type === DisplayDevice::DISPLAY && $user->tokenCan('display') && $user->branch_id === $branchId;
    }

    return $user instanceof User && ($user->branch_id === null || $user->branch_id === $branchId);
});

Broadcast::channel('employee.{employeeId}', function ($user, string $employeeId): bool {
    if (! $user instanceof User) {
        return false;
    }
    $employee = Employee::query()->find($employeeId);

    return $employee !== null && ($user->branch_id === null || $user->branch_id === $employee->branch_id) && ($employee->user_id === $user->id || $user->can('queues.view'));
});
