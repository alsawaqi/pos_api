<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P-F9 — read-only mirror of the shared `pos_offers` table.
 *
 * Merchant-defined promotions (bogo / bundle / multi_buy / cheapest_free /
 * spend_get) the DEVICE evaluates with its pure offers engine. Served in
 * the device config bundle as the top-level `offers` slice; never written
 * here. `config` is the type-specific JSON shape, passed through to the
 * device verbatim. Money inside config is integer BAISAS.
 */
class Offer extends Model
{
    use SoftDeletes;

    protected $table = 'pos_offers';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'auto_apply' => 'boolean',
            'validity_start' => 'datetime',
            'validity_end' => 'datetime',
            'dayofweek_mask' => 'integer',
            'branch_scope_json' => 'array',
            'max_per_order' => 'integer',
        ];
    }
}
