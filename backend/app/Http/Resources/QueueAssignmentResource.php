<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QueueAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'branch_id' => $this->branch_id, 'queue_id' => $this->queue_id, 'employee_id' => $this->employee_id, 'counter_id' => $this->counter_id, 'assignment_type' => $this->assignment_type, 'assigned_at' => $this->assigned_at, 'queue' => new QueueResource($this->whenLoaded('queue')), 'employee' => new EmployeeResource($this->whenLoaded('employee')), 'counter' => new CounterResource($this->whenLoaded('counter'))];
    }
}
