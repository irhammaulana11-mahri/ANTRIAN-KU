<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueAssignment extends Model
{
    protected $table = 'queue_assignments';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'queue_id', 'employee_id', 'counter_id', 'assignment_type', 'reason', 'assigned_at', 'ended_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function queue()
    {
        return $this->belongsTo(Queue::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function counter()
    {
        return $this->belongsTo(Counter::class);
    }
}
