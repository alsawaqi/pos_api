<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReversal extends Model
{
    protected $table = 'pos_payment_reversals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3', 'amount_baisas' => 'integer', 'receipt_json' => 'array',
            'attempted_at' => 'datetime', 'completed_at' => 'datetime',
            'refund_needs_transaction_id' => 'boolean', 'void_needs_session_id' => 'boolean',
        ];
    }
}
