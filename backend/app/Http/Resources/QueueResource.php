<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QueueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'service_type_id' => $this->service_type_id,
            'service_name' => $this->whenLoaded('serviceType', fn () => $this->serviceType->service_name),
            'priority_type_id' => $this->priority_type_id,
            'priority_name' => $this->whenLoaded('priorityType', fn () => $this->priorityType?->priority_name),
            'queue_date' => $this->queue_date?->toDateString(),
            'sequence_no' => $this->sequence_no,
            'queue_number' => $this->queue_number,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->when($request->user()?->can('queues.view'), $this->customer_phone),
            'service_notes' => $this->when($request->user()?->can('queues.view'), $this->service_notes),
            'registration_channel' => $this->registration_channel,
            'status' => $this->status,
            'called_at' => $this->called_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
