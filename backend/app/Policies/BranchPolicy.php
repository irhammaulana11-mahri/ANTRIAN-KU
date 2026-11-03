<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('branches.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->can('branches.view') && ($user->branch_id === null || $user->branch_id === $branch->id);
    }

    public function create(User $user): bool
    {
        return $user->can('branches.manage') && $user->branch_id === null;
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can('branches.manage') && ($user->branch_id === null || $user->branch_id === $branch->id);
    }

    /** Admin Cabang mengubah profil cabangnya sendiri (nama, alamat, telepon); kode dan status hanya oleh yang berwenang penuh. */
    public function updateProfile(User $user, Branch $branch): bool
    {
        return $user->can('branches.update_own') && $user->branch_id === $branch->id;
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->update($user, $branch);
    }
}
