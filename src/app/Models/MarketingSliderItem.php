<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of `pos_marketing_slider_items` (pos_admin-owned, shared
 * charity_db). One ordered slide in a slider — a reference to an advertiser
 * content asset with a per-item on-screen duration (the device caps even a
 * longer video at this). pos_api reads it for the device-config slider slice.
 *
 * @property int $id
 * @property int $slider_id
 * @property int $content_asset_id
 * @property int|null $advertiser_id
 * @property int $sort_order
 * @property int|null $duration_seconds
 */
class MarketingSliderItem extends Model
{
    protected $table = 'pos_marketing_slider_items';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<ContentAsset, $this> */
    public function contentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class, 'content_asset_id');
    }
}
