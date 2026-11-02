<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'actor_id', 'action', 'entity_type', 'entity_id', 'before_data', 'after_data', 'metadata', 'correlation_id', 'created_at'];

    protected function casts(): array
    {
        return ['before_data' => 'array', 'after_data' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
