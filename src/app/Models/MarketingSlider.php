<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of `pos_marketing_sliders` (pos_admin-owned, shared
 * charity_db).
 *
 * A platform-curated advertising loop the device plays on its customer screen.
 * UNLIKE every catalogue slice these are NOT company-scoped — the platform
 * targets ad loops at specific branches/devices via pos_marketing_slider_targets
 * (a slider with no targets plays everywhere). The device-config `sliders` slice
 * reads this; pos_api never writes it.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int $loop_interval_seconds
 * @property string $status
 */
class MarketingSlider extends Model
{
    use SoftDeletes;

    protected $table = 'pos_marketing_sliders';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'loop_interval_seconds' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return HasMany<MarketingSliderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MarketingSliderItem::class, 'slider_id')->orderBy('sort_order');
    }

    /** @return HasMany<MarketingSliderTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(MarketingSliderTarget::class, 'slider_id');
    }
}
