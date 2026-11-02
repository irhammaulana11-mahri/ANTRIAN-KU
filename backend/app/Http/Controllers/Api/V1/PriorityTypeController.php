<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PriorityType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Daftar kategori prioritas untuk petugas yang memverifikasi prioritas saat pendaftaran walk-in. */
class PriorityTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('queues.assign'), 403);
        $branchId = $request->attributes->get('branch_id');
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');

        return response()->json(['data' => PriorityType::query()->where('branch_id', $branchId)->where('is_active', true)->orderBy('weight')->get(['id', 'priority_code', 'priority_name', 'weight'])]);
    }
}
