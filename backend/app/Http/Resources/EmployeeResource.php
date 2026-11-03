<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'branch_id'     => $this->branch_id,
            'unit_id'       => $this->unit_id,
            'unit'          => $this->whenLoaded('unit', fn () => ['id' => $this->unit?->id, 'unit_name' => $this->unit?->unit_name, 'unit_code' => $this->unit?->unit_code]),
            'user_id'       => $this->user_id,
            'employee_code' => $this->employee_code,
            'employee_name' => $this->employee_name,
            'is_active'     => $this->is_active,
            'user'          => $this->whenLoaded('user', fn () => ['id' => $this->user?->id, 'name' => $this->user?->name, 'email' => $this->user?->email, 'roles' => $this->user?->getRoleNames()->values()]),
            'service_types' => ServiceTypeResource::collection($this->whenLoaded('serviceTypes')),
            'counters'      => CounterResource::collection($this->whenLoaded('counters')),
            'created_at'    => $this->created_at,
            'deleted_at'    => $this->deleted_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
