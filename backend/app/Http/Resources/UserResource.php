<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'branch_id' => $this->branch_id, 'unit_id' => $this->unit_id, 'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->id, 'branch_code' => $this->branch->branch_code, 'branch_name' => $this->branch->branch_name] : null), 'unit' => $this->whenLoaded('unit', fn () => $this->unit ? ['id' => $this->unit->id, 'unit_code' => $this->unit->unit_code, 'unit_name' => $this->unit->unit_name] : null), 'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')), 'permissions' => $this->getAllPermissions()->pluck('name')->values(), 'employee' => new EmployeeResource($this->whenLoaded('employee')), 'last_login_at' => $this->last_login_at];
    }
}
