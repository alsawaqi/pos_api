<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8 — POS-owned charity round-up donation (written by the
 * donation.record sync handler). Schema owned by pos_admin's
 * 2026_06_18 migration; pos_api writes it. Unguarded like the other
 * sync-target models.
 */
class RoundupDonation extends Model
{
    protected $table = 'pos_roundup_donations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'bank_response' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'occurred_at' => 'datetime',
        ];
    }
}
