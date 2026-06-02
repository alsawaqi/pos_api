<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Company-level tax (read side for the device config bundle).
 *
 * The merchant portal owns CRUD; the device fetches the active set via
 * GET /device/config and adds each, as its own line, on top of the order total
 * (exclusive). Schema owned by pos_admin's 2026_06_24_010000 migration
 * (pos_taxes).
 */
class Tax extends Model
{
    use SoftDeletes;

    protected $table = 'pos_taxes';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rate_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
