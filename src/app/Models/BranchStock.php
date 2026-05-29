<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only mirror of the shared `pos_branch_stock` table (on-hand
 * ingredient quantity per branch). Served in the device config bundle
 * (Phase 8.1) so the terminal knows local stock; never written here.
 *
 * No soft-deletes — stock rows are zeroed, not deleted.
 */
class BranchStock extends Model
{
    protected $table = 'pos_branch_stock';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'last_movement_at' => 'datetime',
        ];
    }
}
