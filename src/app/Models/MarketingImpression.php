<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 — advertising play-time telemetry (pos_api-OWNED, table
 * pos_marketing_impressions). One row per slide shown on a device's customer
 * screen, written by {@see \App\Actions\Device\Sync\Handlers\SliderDisplayHandler}
 * from a `slider.display` sync event. Aggregated later for advertiser analytics
 * + display-time billing. The UNIQUE (device_id, client_event_id) is the
 * replay guard. money/duration here is integer milliseconds, not baisas.
 */
class MarketingImpression extends Model
{
    protected $table = 'pos_marketing_impressions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'play_duration_ms' => 'integer',
            'viewers_peak' => 'integer',
            'viewers_avg' => 'integer',
            'viewers_distinct' => 'integer',
            'attention_ms' => 'integer',
            'played_at' => 'datetime',
        ];
    }
}
