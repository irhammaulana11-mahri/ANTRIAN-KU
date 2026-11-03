<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'branch_id' => $this->branch_id, 'service_name' => $this->service_name, 'queue_prefix' => $this->queue_prefix, 'priority_enabled' => $this->priority_enabled, 'is_active' => $this->is_active, 'created_at' => $this->created_at, 'deleted_at' => $this->deleted_at, 'updated_at' => $this->updated_at];
    }
}
