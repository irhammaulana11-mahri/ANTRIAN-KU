<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Queue extends Model
{
    public const WAITING = 'WAITING';

    public const ASSIGNED = 'ASSIGNED';

    public const CALLED = 'CALLED';

    public const SERVING = 'SERVING';

    public const COMPLETED = 'COMPLETED';

    public const SKIPPED = 'SKIPPED';

    public const CANCELLED = 'CANCELLED';

    public const NO_SHOW = 'NO_SHOW';

    protected $table = 'queues';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'service_type_id', 'priority_type_id', 'queue_date', 'sequence_no', 'queue_number', 'customer_name', 'customer_phone', 'service_notes', 'registration_channel', 'status', 'token_hash', 'priority_verified_by', 'priority_verified_at', 'called_at', 'completed_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['queue_date' => 'date', 'priority_verified_at' => 'datetime', 'called_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function priorityType()
    {
        return $this->belongsTo(PriorityType::class);
    }

    public function priorityVerifier()
    {
        return $this->belongsTo(User::class, 'priority_verified_by');
    }

    public function assignments()
    {
        return $this->hasMany(QueueAssignment::class);
    }

    public function activeAssignment()
    {
        return $this->hasOne(QueueAssignment::class)->whereNull('ended_at');
    }

    public function serviceSessions()
    {
        return $this->hasMany(ServiceSession::class);
    }

    public function transitions()
    {
        return $this->hasMany(QueueTransition::class);
    }

    public function callLogs()
    {
        return $this->hasMany(QueueCallLog::class);
    }
}
