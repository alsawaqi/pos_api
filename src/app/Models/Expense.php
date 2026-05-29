<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.8 — POS-logged expense (shared pos_expenses, blueprint §5.10/§10.8).
 *
 * A device logs an on-the-spot expense (utility bill, supplier cash payment)
 * via the `expense.log` sync event; it lands status=recorded for the merchant
 * portal's review queue, and feeds the Sales report's net-profit line. Money
 * is decimal(12,3) OMR (handlers convert from wire baisas).
 */
class Expense extends Model
{
    protected $table = 'pos_expenses';

    protected $guarded = [];

    public const STATUS_RECORDED = 'recorded';

    /** @var list<string> */
    public const CATEGORIES = ['utilities', 'supplies', 'maintenance', 'salaries', 'other'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'logged_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
