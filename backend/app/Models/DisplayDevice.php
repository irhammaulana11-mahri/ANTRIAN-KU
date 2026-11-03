<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/** Perangkat TV/layar antrian. Terautentikasi lewat token Sanctum ber-ability "display". */
class DisplayDevice extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    public const DISPLAY = 'DISPLAY';

    public const KIOSK = 'KIOSK';

    protected $table = 'display_devices';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'unit_id', 'device_code', 'device_name', 'credential_hash', 'activation_code', 'device_type', 'last_seen_at', 'revoked_at'];

    protected $hidden = ['credential_hash', 'activation_code'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /** Ability token = tipe perangkat huruf kecil ('display' / 'kiosk'). */
    public function ability(): string
    {
        return strtolower($this->device_type ?: self::DISPLAY);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
