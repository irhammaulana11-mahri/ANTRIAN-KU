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
use Illuminate\Validation\Rule;

/** Pengelolaan perangkat display oleh admin. Kunci pairing hanya ditampilkan sekali. */
class DisplayDeviceController extends Controller
{
    public function __construct(private readonly BranchAuthorizationService $branches, private readonly AuditLogService $audit) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('displays.manage'), 403);
        $branchId = $request->attributes->get('branch_id');

        // Admin Unit hanya melihat perangkat unitnya; Admin Cabang/Super Admin dapat menyaring per unit.
        $unitId = $this->branches->resolveUnit($request->user(), $request->input('unit_id'));

        return DisplayDeviceResource::collection(DisplayDevice::query()->with('unit')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->orderBy('device_name')->paginate($request->integer('per_page', 50)));
    }

    public function store(DisplayDeviceRequest $request): JsonResponse
    {
        $branchId = $this->branches->resolve($request->user(), $request->input('branch_id'));
        abort_unless($branchId, 422, 'branch_id wajib diisi oleh Super Admin.');
        $unitId = $this->branches->resolveUnit($request->user(), $request->input('unit_id'));
        $code = $this->generateActivationCode();
        $device = DisplayDevice::create(['id' => (string) Str::uuid(), 'branch_id' => $branchId, 'unit_id' => $unitId, 'device_code' => $request->string('device_code')->toString(), 'device_name' => $request->string('device_name')->toString(), 'device_type' => $request->input('device_type', 'DISPLAY'), 'credential_hash' => hash('sha256', $code)]);
        $device->load('unit');
        $this->audit->recordModel('display_device.created', $device, $request->user()->id, null, ['unit_id' => $unitId, 'device_code' => $device->device_code, 'device_type' => $device->device_type]);

        return response()->json(['data' => new DisplayDeviceResource($device->load('unit')), 'credential' => $code, 'message' => 'Perangkat dibuat. Simpan kunci pairing ini; tidak akan ditampilkan lagi.'], 201);
    }

    /** Menentukan unit untuk perangkat lama yang belum berunit. Unit yang sudah ditetapkan tidak dapat diubah. */
    public function assignUnit(Request $request, DisplayDevice $device): JsonResponse
    {
        $this->authorizeDevice($request, $device);
        abort_if($device->unit_id !== null, 422, 'Unit perangkat sudah ditentukan dan tidak dapat diubah.');
        $data = $request->validate(['unit_id' => ['required', 'uuid', Rule::exists('units', 'id')->where('branch_id', $device->branch_id)->whereNull('deleted_at')]]);
        $this->branches->resolveUnit($request->user(), $data['unit_id']);
        $device->forceFill(['unit_id' => $data['unit_id']])->save();
        $this->audit->recordModel('display_device.unit_assigned', $device, $request->user()->id, null, ['unit_id' => $data['unit_id']]);

        return response()->json(['data' => new DisplayDeviceResource($device->load('unit')), 'message' => 'Unit perangkat ditentukan.']);
    }

    public function rotate(Request $request, DisplayDevice $device): JsonResponse
    {
        $this->authorizeDevice($request, $device);
        $code = $this->generateActivationCode();
        $device->forceFill(['credential_hash' => hash('sha256', $code), 'revoked_at' => null])->save();
        $device->tokens()->delete();
        $this->audit->recordModel('display_device.rotated', $device, $request->user()->id);

        return response()->json(['data' => new DisplayDeviceResource($device->load('unit')), 'credential' => $code, 'message' => 'Kunci pairing baru dibuat. Perangkat lama otomatis keluar.']);
    }

    private function generateActivationCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // tanpa karakter ambigu (0/O, 1/I)
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while (DisplayDevice::withTrashed()->where('credential_hash', hash('sha256', $code))->exists());

        return $code;
    }

    public function revoke(Request $request, DisplayDevice $device): JsonResponse
    {
        $this->authorizeDevice($request, $device);
        $device->forceFill(['revoked_at' => now()])->save();
        $device->tokens()->delete();
        $this->audit->recordModel('display_device.revoked', $device, $request->user()->id);

        return response()->json(['data' => new DisplayDeviceResource($device), 'message' => 'Perangkat dinonaktifkan.']);
    }

    /** Hapus (soft delete): token dicabut dan kode perangkat dapat dipakai lagi. */
    public function destroy(Request $request, DisplayDevice $device): JsonResponse
    {
        $this->authorizeDevice($request, $device);
        $device->tokens()->delete();
        $this->audit->recordModel('display_device.deleted', $device, $request->user()->id, ['device_code' => $device->device_code], null);
        $device->delete();

        return response()->json(['message' => 'Perangkat dihapus.']);
    }

    private function authorizeDevice(Request $request, DisplayDevice $device): void
    {
        abort_unless($request->user()->can('displays.manage'), 403);
        abort_if($request->user()->branch_id !== null && $request->user()->branch_id !== $device->branch_id, 403, 'Perangkat bukan milik cabang Anda.');
        abort_if($request->user()->unit_id !== null && $request->user()->unit_id !== $device->unit_id, 403, 'Perangkat bukan milik unit Anda.');
    }
}
