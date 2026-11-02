<?php

namespace App\Policies;

use App\Models\Counter;
use App\Models\User;

class CounterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('counters.view');
    }

    public function view(User $user, Counter $counter): bool
    {
        return $user->can('counters.view') && ($user->branch_id === null || $user->branch_id === $counter->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->can('counters.manage');
    }

    public function update(User $user, Counter $counter): bool
    {
        return $user->can('counters.manage') && ($user->branch_id === null || $user->branch_id === $counter->branch_id);
    }

    public function delete(User $user, Counter $counter): bool
    {
        return $this->update($user, $counter);
    }
}
