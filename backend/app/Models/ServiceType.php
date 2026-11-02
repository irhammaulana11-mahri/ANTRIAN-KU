<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceType extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'service_code', 'service_name', 'queue_prefix', 'priority_enabled', 'is_active'];

    protected function casts(): array
    {
        return ['priority_enabled' => 'boolean', 'is_active' => 'boolean'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_services');
    }
}
