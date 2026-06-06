<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A non-merchant split line of a {@see MerchantCommissionProfile}
 * (pos_commission_shares). party_type is platform | bank | other;
 * the merchant takes the residual and is not a row here.
 */
class MerchantCommissionShare extends Model
{
    protected $table = 'pos_commission_shares';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
        ];
    }
}
