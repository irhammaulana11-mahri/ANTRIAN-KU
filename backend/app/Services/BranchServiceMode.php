<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Apakah cabang membuka layanan sendiri (antrian tanpa unit)? Bila tidak, seluruh pelayanan ada di unit.
 * Default true: cabang tanpa pengaturan tetap melayani langsung seperti sebelum adanya unit.
 */
class BranchServiceMode
{
    public function servesDirectly(?string $branchId): bool
    {
        if ($branchId === null) {
            return true;
        }

        return (bool) (DB::table('queue_settings')->where('branch_id', $branchId)->value('branch_serves_directly') ?? true);
    }
}
