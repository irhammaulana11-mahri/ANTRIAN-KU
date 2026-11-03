<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BranchAuthorizationService
{
    public function resolve(User $user, ?string $requestedBranchId = null): ?string
    {
        if ($user->branch_id === null) {
            return $requestedBranchId;
        }

        if ($requestedBranchId !== null && $requestedBranchId !== $user->branch_id) {
            throw ValidationException::withMessages(['branch_id' => 'Anda tidak memiliki akses ke cabang tersebut.']);
        }

        return $user->branch_id;
    }

    public function fromRequest(Request $request): ?string
    {
        return $this->resolve($request->user(), $request->input('branch_id'));
    }

    /**
     * ID unit yang dimiliki pengguna, atau null bila pengguna lintas-unit (Super Admin / Admin Cabang).
     * Admin Unit: unit_id terisi → cakupan hanya unit itu.
     */
    public function resolveUnit(User $user, ?string $requestedUnitId = null): ?string
    {
        if ($user->unit_id === null) {
            return $requestedUnitId; // Super Admin / Admin Cabang: pakai filter dari request
        }

        if ($requestedUnitId !== null && $requestedUnitId !== $user->unit_id) {
            throw ValidationException::withMessages(['unit_id' => 'Anda tidak memiliki akses ke unit tersebut.']);
        }

        return $user->unit_id;
    }
}
