<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CounterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'branch_id' => $this->branch_id, 'counter_code' => $this->counter_code, 'counter_name' => $this->counter_name, 'counter_type' => $this->counter_type, 'is_active' => $this->is_active, 'created_at' => $this->created_at, 'deleted_at' => $this->deleted_at, 'updated_at' => $this->updated_at];
    }
}
