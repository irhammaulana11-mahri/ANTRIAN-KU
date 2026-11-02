<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogService
{
    public function record(string $action, string $entityType, ?string $entityId, ?string $branchId, ?string $actorId, ?array $before = null, ?array $after = null, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'id' => (string) Str::orderedUuid(),
            'branch_id' => $branchId,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before,
            'after_data' => $after,
            'metadata' => $metadata,
            'correlation_id' => request()->header('X-Correlation-ID') ?: (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }

    public function recordModel(string $action, Model $model, ?string $actorId, ?array $before = null, ?array $after = null, array $metadata = []): AuditLog
    {
        return $this->record($action, $model->getMorphClass(), (string) $model->getKey(), $model->getAttribute('branch_id'), $actorId, $before, $after, $metadata);
    }
}
