<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only mirror of `pos_marketing_slider_targets` (pos_admin-owned, shared
 * charity_db). Where a slider plays: a specific device, or a whole branch
 * (device_id null). A slider with NO target rows plays everywhere. pos_api reads
 * this to scope the device-config slider slice to the calling device.
 *
 * @property int $id
 * @property int $slider_id
 * @property int|null $branch_id
 * @property int|null $device_id
 */
class MarketingSliderTarget extends Model
{
    protected $table = 'pos_marketing_slider_targets';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'device_id' => 'integer',
        ];
    }
}
