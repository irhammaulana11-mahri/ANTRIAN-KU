<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueSequence extends Model
{
    protected $table = 'queue_sequences';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'branch_id', 'service_type_id', 'queue_date', 'last_number'];

    protected function casts(): array
    {
        return ['queue_date' => 'date', 'last_number' => 'integer'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class);
    }
}
