<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase B — read-mostly mirror of the shared `pos_comp_reasons` table
 * (CRUD in pos_merchant; schema in pos_admin's 2026_07_02 migration).
 * order.create resolves each comp's reason here and enforces the
 * per-comp max_amount cap.
 */
class CompReason extends Model
{
    use SoftDeletes;

    protected $table = 'pos_comp_reasons';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_amount' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }
}
