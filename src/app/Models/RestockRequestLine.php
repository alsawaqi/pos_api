<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.8 — restock request line (shared pos_restock_request_lines).
 *
 * One row per (request, ingredient); unit_at_set is denormalised from the
 * ingredient at request time. quantity_allocated starts 0 (the merchant
 * portal sets it on allocation).
 */
class RestockRequestLine extends Model
{
    protected $table = 'pos_restock_request_lines';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:3',
            'quantity_allocated' => 'decimal:3',
        ];
    }
}
