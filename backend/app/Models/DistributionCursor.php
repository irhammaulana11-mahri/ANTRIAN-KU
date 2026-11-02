<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistributionCursor extends Model
{
    protected $table = 'distribution_cursors';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'service_type_id', 'last_employee_id'];
}
