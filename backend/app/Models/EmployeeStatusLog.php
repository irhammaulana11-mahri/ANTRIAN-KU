<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeStatusLog extends Model
{
    public $timestamps = false;

    protected $table = 'employee_status_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'employee_id', 'from_status', 'to_status', 'reason', 'heartbeat_at', 'recorded_at'];

    protected function casts(): array
    {
        return ['heartbeat_at' => 'datetime', 'recorded_at' => 'datetime'];
    }
}
