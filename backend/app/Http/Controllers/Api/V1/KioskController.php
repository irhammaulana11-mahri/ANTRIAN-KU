<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\KioskQueueRequest;
use App\Models\DisplayDevice;
use App\Models\ServiceType;
use App\Services\BranchAvailabilityService;
use App\Services\BranchServiceMode;
use App\Services\QueueDistributionEngine;
use App\Services\QueueManagementService;
use App\Services\WaitEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Endpoint kiosk/tablet pengambilan nomor walk-in. Hanya token perangkat bertipe KIOSK; terkunci ke cabang perangkat. */
class KioskController extends Controller
{
    public function __construct(
        private readonly QueueManagementService $queues,
        private readonly QueueDistributionEngine $distribution,
        private readonly BranchAvailabilityService $availability,
        private readonly WaitEstimator $estimator,
        private readonly BranchServiceMode $mode,
    ) {}

    public function config(Request $request): JsonResponse
    {
        /** @var DisplayDevice $device */
        $device = $request->user()->loadMissing(['branch', 'unit']);
        $status = $this->availability->status($device->branch_id);
        if ($device->unit_id === null && ! $this->mode->servesDirectly($device->branch_id)) {
            $status['is_open'] = false;
            $status['message'] = 'Cabang tidak membuka layanan langsung. Perangkat ini belum ditautkan ke unit.';
        }

        return response()->json(['data' => [
            'server_time' => now()->toIso8601String(),
            'branch' => ['id' => $device->branch_id, 'branch_name' => $device->branch?->branch_name],
            'unit' => $device->unit_id ? ['id' => $device->unit_id, 'unit_name' => $device->unit?->unit_name] : null,
            'services' => ServiceType::query()->where('branch_id', $device->branch_id)->where('is_active', true)->when($device->unit_id, fn ($q) => $q->where(fn ($w) => $w->whereNull('unit_id')->orWhere('unit_id', $device->unit_id)))->orderBy('service_name')->get(['id', 'service_name', 'queue_prefix']),
            'availability' => ['is_open' => $status['is_open'], 'message' => $status['message'], 'opens_at' => $status['opens_at'], 'closes_at' => $status['closes_at'], 'waiting_count' => $status['waiting_count']],
        ]]);
    }

    public function store(KioskQueueRequest $request): JsonResponse
    {
        /** @var DisplayDevice $device */
        $device = $request->user()->loadMissing('branch');
        abort_if($device->unit_id === null && ! $this->mode->servesDirectly($device->branch_id), 422, 'Cabang tidak membuka layanan langsung. Perangkat ini belum ditautkan ke unit.');
        $allowed = ServiceType::query()->whereKey($request->input('service_type_id'))->where('branch_id', $device->branch_id)->where('is_active', true)
            ->when($device->unit_id, fn ($q) => $q->where(fn ($w) => $w->whereNull('unit_id')->orWhere('unit_id', $device->unit_id)))->exists();
        abort_unless($allowed, 422, 'Layanan tidak tersedia di unit ini.');
        $data = $request->validated() + ['branch_id' => $device->branch_id, 'unit_id' => $device->unit_id];

        $result = $this->queues->register($data, 'WALK_IN', null);
        $this->distribution->assignNext($device->branch_id, $data['service_type_id']);
        $queue = $result['queue']->refresh()->load('serviceType');
        $estimate = $this->estimator->forQueue($queue);

        return response()->json(['data' => [
            'queue' => ['queue_number' => $queue->queue_number, 'customer_name' => $queue->customer_name, 'service_name' => $queue->serviceType?->service_name, 'created_at' => $queue->created_at, 'status' => $queue->status],
            'branch_name' => $device->branch?->branch_name,
            'token' => $result['token'],
            'position' => $estimate['position'],
            'estimated_wait_seconds' => $estimate['estimated_wait_seconds'],
        ], 'message' => 'Nomor antrian dibuat.'], 201);
    }
}
