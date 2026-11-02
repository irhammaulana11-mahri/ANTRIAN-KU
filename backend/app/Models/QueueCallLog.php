<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueCallLog extends Model
{
    public $timestamps = false;

    protected $table = 'queue_call_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'queue_id', 'employee_id', 'counter_id', 'call_type', 'event_id', 'called_at'];

    protected function casts(): array
    {
        return ['called_at' => 'datetime'];
    }
}
