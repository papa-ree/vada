<?php

namespace Bale\Vada\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pemetaan nomor WhatsApp tenant. Satu-satunya tabel Vada di landlord.
 */
class VadaChannel extends Model
{
    use HasUuids;

    protected $table = 'vada_channels';

    protected $fillable = [
        'bale_id',
        'device_id',
        'label',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
