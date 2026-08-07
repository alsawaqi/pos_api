<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Published and inside its validity window at `$at`.
     *
     * SHARED PREDICATE — do not inline this. Two places decide whether a loop
     * is live: the device-config slice (what we SEND a device) and the
     * slider.display gate (what we ACCEPT back as a billable play). They must
     * agree. They did not: the impression gate copied only the targeting half
     * and dropped status + window, so a draft, paused or long-ended campaign
     * kept accruing impressions that advertiser invoices then billed.
     *
     * Pass the PLAY time, not now(), when judging a reported impression — a
     * device is offline-first and may report a genuine play hours or days
     * later, by which time the campaign may legitimately have ended.
     */
    public function scopeLiveAt(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }

    /**
     * In this device's loop: targeted at the device, at its branch
     * (device_id null), or untargeted (= plays everywhere).
     *
     * The other half of the shared predicate — see {@see scopeLiveAt}.
     */
    public function scopeServedToDevice(Builder $query, Device $device): Builder
    {
        $branchId = (int) $device->branch_id;

        return $query->where(function (Builder $q) use ($device, $branchId): void {
            $q->whereHas('targets', function (Builder $t) use ($device, $branchId): void {
                $t->where('device_id', $device->id)
                    ->orWhere(function (Builder $w) use ($branchId): void {
                        $w->whereNull('device_id')->where('branch_id', $branchId);
                    });
            })->orWhereDoesntHave('targets');
        });
    }
}
