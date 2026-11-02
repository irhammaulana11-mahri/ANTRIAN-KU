<?php

namespace App\Policies;

use App\Models\ServiceType;
use App\Models\User;

class ServiceTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('services.view');
    }

    public function view(User $user, ServiceType $service): bool
    {
        return $user->can('services.view') && ($user->branch_id === null || $user->branch_id === $service->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->can('services.manage');
    }

    public function update(User $user, ServiceType $service): bool
    {
        return $user->can('services.manage') && ($user->branch_id === null || $user->branch_id === $service->branch_id);
    }

    public function delete(User $user, ServiceType $service): bool
    {
        return $this->update($user, $service);
    }
}
