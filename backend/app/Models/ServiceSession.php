<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceSession extends Model
{
    protected $table = 'service_sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'queue_id', 'employee_id', 'counter_id', 'started_at', 'completed_at', 'outcome', 'notes'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
