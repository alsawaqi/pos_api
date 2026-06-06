<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The merchant's commission profile (pos_commission_profiles, owned by
 * pos_admin). pos_api only READS it — at order.pay it applies the share
 * lines to the sale to write the per-sale breakdown. Distinct from the
 * charity round-up commission_profiles the device carries.
 */
class MerchantCommissionProfile extends Model
{
    protected $table = 'pos_commission_profiles';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'merchant_percent' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<MerchantCommissionShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(MerchantCommissionShare::class, 'commission_profile_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
