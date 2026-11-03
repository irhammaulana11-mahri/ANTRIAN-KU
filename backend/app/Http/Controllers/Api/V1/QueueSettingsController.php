<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\HolidayRequest;
use App\Http\Requests\QueueSettingsRequest;
use App\Services\BranchAvailabilityService;
use App\Services\QueueSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueueSettingsController extends Controller
{
    public function __construct(private readonly QueueSettingsService $settings, private readonly BranchAvailabilityService $availability) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $branchId = $this->branchId($request);

        return response()->json(['data' => $this->settings->get($branchId) + ['availability' => $this->availability->status($branchId)]]);
    }

    public function update(QueueSettingsRequest $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $data = $this->settings->update($branchId, $request->safe()->only(['branch_serves_directly', 'operating_hours']), $request->user()->id);

        return response()->json(['data' => $data + ['availability' => $this->availability->status($branchId)], 'message' => 'Pengaturan antrian disimpan.']);
    }

    public function storeHoliday(HolidayRequest $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $this->settings->addHoliday($branchId, $request->string('holiday_date')->toString(), $request->string('holiday_name')->toString(), $request->user()->id);

        return response()->json(['data' => $this->settings->get($branchId), 'message' => 'Hari libur disimpan.'], 201);
    }

    public function destroyHoliday(Request $request, string $holiday): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $branchId = $this->branchId($request);
        abort_unless($this->settings->removeHoliday($branchId, $holiday, $request->user()->id), 404, 'Hari libur tidak ditemukan.');

        return response()->json(['data' => $this->settings->get($branchId), 'message' => 'Hari libur dihapus.']);
    }

    private function branchId(Request $request): string
    {
        $branchId = $request->attributes->get('branch_id');
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');

        return $branchId;
    }
}
