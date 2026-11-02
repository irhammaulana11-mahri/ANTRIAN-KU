<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'branch_code' => $this->branch_code, 'branch_name' => $this->branch_name, 'address' => $this->address, 'phone' => $this->phone, 'is_active' => $this->is_active, 'employees_count' => $this->whenCounted('employees'), 'counters_count' => $this->whenCounted('counters'), 'service_types_count' => $this->whenCounted('serviceTypes'), 'created_at' => $this->created_at, 'deleted_at' => $this->deleted_at, 'updated_at' => $this->updated_at];
    }
}
