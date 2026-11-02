<?php

namespace App\Http\Resources;

use App\Models\Queue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QueueTrackingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $counter = $this->relationLoaded('activeAssignment') ? $this->activeAssignment?->counter : null;

        return [
            'queue_number' => $this->queue_number,
            'branch_name' => $this->relationLoaded('branch') ? $this->branch?->branch_name : null,
            'service_name' => $this->serviceType?->service_name,
            'status' => $this->status,
            'queue_date' => $this->queue_date?->toDateString(),
            'customer_name' => $this->customer_name,
            'registration_channel' => $this->registration_channel,
            'created_at' => $this->created_at,
            'called_at' => $this->called_at,
            'position' => $this->position ?? null,
            'ahead' => $this->ahead ?? null,
            'estimated_wait_seconds' => $this->estimated_wait_seconds ?? null,
            'counter' => $counter && in_array($this->status, [Queue::ASSIGNED, Queue::CALLED, Queue::SERVING], true) ? ['counter_code' => $counter->counter_code, 'counter_name' => $counter->counter_name] : null,
            'can_cancel' => in_array($this->status, [Queue::WAITING, Queue::ASSIGNED, Queue::CALLED], true),
        ];
    }
}
