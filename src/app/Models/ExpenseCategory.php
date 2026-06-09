<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Company-level expense category (read side for the device config bundle).
 *
 * The merchant portal owns CRUD; the device fetches the active set via
 * GET /device/config and offers it in the expense-logging screen, and
 * expense.log validates the submitted key against it. Schema owned by
 * pos_admin's 2026_06_27_010000 migration (pos_expense_categories).
 */
class ExpenseCategory extends Model
{
    use SoftDeletes;

    protected $table = 'pos_expense_categories';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];
}
