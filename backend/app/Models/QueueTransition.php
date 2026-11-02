<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueTransition extends Model
{
    public $timestamps = false;

    protected $table = 'queue_transitions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'queue_id', 'from_status', 'to_status', 'actor_id', 'reason', 'transitioned_at'];

    protected function casts(): array
    {
        return ['transitioned_at' => 'datetime'];
    }
}
