<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Counter extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'counter_code', 'counter_name', 'counter_type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_counters');
    }

    public function assignments()
    {
        return $this->hasMany(QueueAssignment::class);
    }

    public function activeAssignments()
    {
        return $this->hasMany(QueueAssignment::class)->whereNull('ended_at');
    }
}
