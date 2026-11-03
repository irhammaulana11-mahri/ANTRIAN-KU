<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('units.view');
    }

    public function view(User $user, Unit $unit): bool
    {
        if (! $user->can('units.view')) return false;
        // Super Admin: semua unit
        if ($user->branch_id === null) return true;
        // Admin Cabang / Admin Unit: hanya cabang yang sama
        if ($user->branch_id !== $unit->branch_id) return false;
        // Admin Unit: hanya unitnya sendiri
        if ($user->unit_id !== null) return $user->unit_id === $unit->id;
        return true;
    }

    public function create(User $user): bool
    {
        // Super Admin dan Admin Cabang dapat membuat unit; Admin Unit tidak
        return $user->can('units.manage') && $user->unit_id === null;
    }

    public function update(User $user, Unit $unit): bool
    {
        return $this->create($user) && ($user->branch_id === null || $user->branch_id === $unit->branch_id);
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $this->update($user, $unit);
    }
}
