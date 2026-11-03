<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'unit_id', 'user_id', 'employee_code', 'employee_name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceTypes()
    {
        return $this->belongsToMany(ServiceType::class, 'employee_services');
    }

    public function counters()
    {
        return $this->belongsToMany(Counter::class, 'employee_counters');
    }

    public function assignments()
    {
        return $this->hasMany(QueueAssignment::class);
    }

    public function activeAssignments()
    {
        return $this->hasMany(QueueAssignment::class)->whereNull('ended_at');
    }

    public function serviceSessions()
    {
        return $this->hasMany(ServiceSession::class);
    }

    public function activeServiceSessions()
    {
        return $this->hasMany(ServiceSession::class)->whereNull('completed_at');
    }

    public function statusLogs()
    {
        return $this->hasMany(EmployeeStatusLog::class);
    }
}
