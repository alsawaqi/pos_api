<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of the shared `pos_loyalty_rules` table.
 * Served in the device config bundle (Phase 8.1); never written here.
 * `config_json` is the freeform rule config (visit_based / spend_based)
 * the on-device loyalty evaluator consumes.
 */
class LoyaltyRule extends Model
{
    use SoftDeletes;

    protected $table = 'pos_loyalty_rules';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config_json' => 'array',
            'validity_start' => 'datetime',
            'validity_end' => 'datetime',
        ];
    }
}
