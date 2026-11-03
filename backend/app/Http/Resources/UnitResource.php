<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'branch_id'    => $this->branch_id,
            'unit_code'    => $this->unit_code,
            'unit_name'    => $this->unit_name,
            'address'      => $this->address,
            'phone'        => $this->phone,
            'is_active'    => $this->is_active,
            'employees_count' => $this->whenCounted('employees'),
            'counters_count'  => $this->whenCounted('counters'),
            'deleted_at'   => $this->deleted_at,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
