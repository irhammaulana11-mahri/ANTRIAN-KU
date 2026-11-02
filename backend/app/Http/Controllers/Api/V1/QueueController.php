<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\QueueCancellationRequest;
use App\Http\Requests\QueuePriorityVerificationRequest;
use App\Http\Requests\QueueRegistrationRequest;
use App\Http\Requests\QueueStatusRequest;
use App\Http\Requests\QueueTransferRequest;
use App\Http\Requests\TokenCancellationRequest;
use App\Http\Resources\QueueResource;
use App\Http\Resources\QueueTrackingResource;
use App\Models\Queue;
use App\Models\User;
use App\Services\QueueDistributionEngine;
use App\Services\QueueManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function __construct(private readonly QueueManagementService $queues, private readonly QueueDistributionEngine $distribution) {}

    public function walkIn(QueueRegistrationRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('queues.register') || $request->user()->can('queues.assign'), 403, 'Anda tidak berwenang mendaftarkan antrian walk-in.');
        $result = $this->registerAndAssign($request->validated(), 'WALK_IN', $request->user());

        return response()->json(['data' => ['queue' => new QueueResource($result['queue']), 'token' => $result['token']], 'message' => 'Nomor antrian walk-in berhasil dibuat.'], 201);
    }

    public function online(QueueRegistrationRequest $request): JsonResponse
    {
        $result = $this->registerAndAssign($request->validated(), 'ONLINE', $request->user());

        return response()->json(['data' => ['queue' => new QueueResource($result['queue']), 'token' => $result['token']], 'message' => 'Pendaftaran online berhasil dibuat.'], 201);
    }

    public function track(string $token): QueueTrackingResource
    {
        return new QueueTrackingResource($this->queues->track($token));
    }

    public function cancelByToken(TokenCancellationRequest $request): QueueTrackingResource
    {
        $queue = $this->queues->cancelByToken($request->string('token')->toString(), $request->input('reason'));
        $this->distribution->assignPending($queue->branch_id);

        return new QueueTrackingResource($queue);
    }

    public function history(Request $request)
    {
        abort_unless($request->user()->can('queues.view'), 403);

        return QueueResource::collection($this->queues->history($request)->paginate($request->integer('per_page', 20)));
    }

    public function cancel(QueueCancellationRequest $request, Queue $queue): QueueResource
    {
        abort_unless($request->user()->can('queues.cancel'), 403);

        $cancelled = $this->queues->cancel($queue, $request->user(), $request->input('token'), $request->input('reason'));
        $this->distribution->assignPending($cancelled->branch_id);

        return new QueueResource($cancelled);
    }

    public function verifyPriority(QueuePriorityVerificationRequest $request, Queue $queue): QueueResource
    {
        return new QueueResource($this->queues->verifyPriority($queue, $request->string('priority_type_id')->toString(), $request->user(), $request->input('reason')));
    }

    public function transfer(QueueTransferRequest $request, Queue $queue): QueueResource
    {
        $transferred = $this->queues->transfer($queue, $request->string('target_service_type_id')->toString(), $request->user(), $request->string('reason')->toString());
        // Petugas yang baru dibebaskan dan antrian yang baru masuk layanan tujuan langsung dicarikan pasangan.
        $this->distribution->assignPending($transferred->branch_id);

        return new QueueResource($transferred->refresh()->load(['serviceType', 'priorityType']));
    }

    public function changeStatus(QueueStatusRequest $request, Queue $queue): QueueResource
    {
        $updated = $this->queues->changeStatus($queue, $request->string('status')->toString(), $request->user(), $request->input('reason'));
        if (in_array($updated->status, [Queue::COMPLETED, Queue::SKIPPED, Queue::NO_SHOW, Queue::CANCELLED], true)) {
            $this->distribution->assignPending($updated->branch_id);
        }

        return new QueueResource($updated->refresh()->load(['serviceType', 'priorityType']));
    }

    private function registerAndAssign(array $data, string $channel, ?User $actor): array
    {
        $result = $this->queues->register($data, $channel, $actor);
        $this->distribution->assignNext($data['branch_id'], $data['service_type_id'], $actor);
        $result['queue']->refresh()->load(['serviceType', 'priorityType']);

        return $result;
    }
}
