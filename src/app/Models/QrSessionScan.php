<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrSessionScan extends Model
{
    protected $table = 'pos_qr_session_scans';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime', 'created_at' => 'datetime',
            'latitude' => 'decimal:7', 'longitude' => 'decimal:7',
            'accuracy_m' => 'integer', 'distance_m' => 'integer',
        ];
    }
}
