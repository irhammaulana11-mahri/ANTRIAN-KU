<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisplayDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'branch_id' => $this->branch_id, 'device_code' => $this->device_code, 'device_name' => $this->device_name, 'device_type' => $this->device_type, 'last_seen_at' => $this->last_seen_at, 'revoked_at' => $this->revoked_at, 'is_online' => $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(2)), 'created_at' => $this->created_at];
    }
}
