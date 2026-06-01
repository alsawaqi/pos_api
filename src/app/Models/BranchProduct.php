<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-branch product availability + unit stock (pivot pos_branch_product).
 *
 * pos_api reads availability for the config bundle and writes stock_qty here
 * when a unit-tracked product is sold (and restores it on void). NULL
 * stock_qty = not unit-tracked at that branch.
 */
class BranchProduct extends Model
{
    protected $table = 'pos_branch_product';

    /** @var list<string> */
    protected $fillable = [
        'branch_id',
        'product_id',
        'is_available',
        'stock_qty',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'stock_qty' => 'decimal:3',
        ];
    }
}
