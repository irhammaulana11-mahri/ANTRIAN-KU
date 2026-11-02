<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DisplayDeviceRequest;
use App\Http\Resources\DisplayDeviceResource;
use App\Models\DisplayDevice;
use App\Services\AuditLogService;
use App\Services\BranchAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Pengelolaan perangkat display oleh admin. Kunci pairing hanya ditampilkan sekali. */
class DisplayDeviceController extends Controller
{
    public function __construct(private readonly BranchAuthorizationService $branches, private readonly AuditLogService $audit) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('displays.manage'), 403);
        $branchId = $request->attributes->get('branch_id');

        return DisplayDeviceResource::collection(DisplayDevice::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('device_name')->paginate($request->integer('per_page', 50)));
    }

    public function store(DisplayDeviceRequest $request): JsonResponse
    {
        $branchId = $this->branches->resolve($request->user(), $request->input('branch_id'));
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');
        $id = (string) Str::uuid();
        $secret = Str::random(40);
        $device = DisplayDevice::create(['id' => $id, 'branch_id' => $branchId, 'device_code' => $request->string('device_code')->toString(), 'device_name' => $request->string('device_name')->toString(), 'device_type' => $request->input('device_type', 'DISPLAY'), 'credential_hash' => hash('sha256', $secret)]);
        $this->audit->recordModel('display_device.created', $device, $request->user()->id, null, ['device_code' => $device->device_code, 'device_type' => $device->device_type]);

        return response()->json(['data' => new DisplayDeviceResource($device), 'credential' => $id.'.'.$secret, 'message' => 'Perangkat dibuat. Simpan kunci pairing ini; tidak akan ditampilkan lagi.'], 201);
    }

    public function rotate(Request $request, DisplayDevice $device): JsonResponse
    {
        $this->authorizeDevice($request, $device);
        $secret = Str::random(40);
        $device->forceFill(['credential_hash' => hash('sha256', $secret), 'revoked_at' => null])->save();
        $device->tokens()->delete();
        $this->audit->recordModel('display_device.rotated', $device, $request->user()->id);

        return response()->json(['data' => new DisplayDeviceResource($device), 'credential' => $device->id.'.'.$secret, 'message' => 'Kunci pairing baru dibuat. Perangkat lama otomatis keluar.']);
    }

    public function revoke(Request $request, DisplayDevice $device): JsonResponse
    {
        $this->authorizeDevice($request, $device);
        $device->forceFill(['revoked_at' => now()])->save();
        $device->tokens()->delete();
        $this->audit->recordModel('display_device.revoked', $device, $request->user()->id);

        return response()->json(['data' => new DisplayDeviceResource($device), 'message' => 'Perangkat dinonaktifkan.']);
    }

    private function authorizeDevice(Request $request, DisplayDevice $device): void
    {
        abort_unless($request->user()->can('displays.manage'), 403);
        abort_if($request->user()->branch_id !== null && $request->user()->branch_id !== $device->branch_id, 403, 'Perangkat bukan milik cabang Anda.');
    }
}
