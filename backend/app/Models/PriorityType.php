<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriorityType extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'priority_code', 'priority_name', 'weight', 'aging_rate', 'aging_interval_seconds', 'verification_required', 'is_active'];

    protected function casts(): array
    {
        return ['weight' => 'integer', 'aging_rate' => 'integer', 'aging_interval_seconds' => 'integer', 'verification_required' => 'boolean', 'is_active' => 'boolean'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
