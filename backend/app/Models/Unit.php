<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unit pelayanan di bawah cabang (misal: ULP, Rayon, dll.).
 * Loket, pegawai, dan perangkat kiosk/display bisa dikaitkan ke unit tertentu.
 */
class Unit extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'unit_code', 'unit_name', 'address', 'phone', 'is_active'];

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
        return $this->hasMany(Employee::class);
    }

    public function counters()
    {
        return $this->hasMany(Counter::class);
    }

    public function displayDevices()
    {
        return $this->hasMany(DisplayDevice::class);
    }
}
