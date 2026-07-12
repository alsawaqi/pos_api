<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\MarketingImpression;
use App\Models\MarketingSlider;
use App\Models\MarketingSliderItem;
use App\Models\SyncEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Phase 3 — records one advertising-slide impression (play-time) from a
 * `slider.display` sync event into pos_marketing_impressions, scoped to the
 * reporting device's company + branch.
 *
 * Idempotent on the event's client_event_id: the ingest layer already dedups a
 * replayed batch before dispatch, and the updateOrCreate on
 * (device_id, client_event_id) is the backstop so a re-processed FAILED event
 * never double-counts a play.
 */
class SliderDisplayHandler implements SyncEventHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;

        $validator = Validator::make($payload, [
            'slider_id' => ['required', 'integer'],
            'slider_item_id' => ['required', 'integer'],
            'content_asset_id' => ['required', 'integer'],
            'advertiser_id' => ['sometimes', 'nullable', 'integer'],
            'duration_ms' => ['required', 'integer', 'min:1'],
            'played_at' => ['sometimes', 'nullable', 'date'],
            // Anonymous audience measurement (optional; only sent when the
            // device's camera-based counter is enabled). Aggregate counts only.
            'viewers_peak' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'viewers_avg' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'viewers_distinct' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'attention_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('invalid slider.display payload: '.implode('; ', $validator->errors()->all()));
        }

        $playedAt = isset($payload['played_at'])
            ? Carbon::parse((string) $payload['played_at'])
            : $event->client_timestamp;

        // Phase 4 — billing integrity. Impressions feed advertiser billing and
        // competitor analytics, so we trust NEITHER the child ids nor the
        // (cross-company by design) content_asset/advertiser the device sent.
        // Resolve the slide UNDER its slider, then RE-DERIVE the asset +
        // advertiser from the row — a device cannot misattribute a play.
        $item = MarketingSliderItem::query()
            ->where('id', (int) $payload['slider_item_id'])
            ->where('slider_id', (int) $payload['slider_id'])
            ->first();
        if ($item === null) {
            throw new RuntimeException('slider.display references an unknown slide');
        }

        // The correct ownership axis here is NOT company (sliders are
        // cross-company): it is "was this slider in the loop this device was
        // told to play". Mirror the exact targeting predicate the device-config
        // slider slice uses (BuildDeviceConfigAction): targets this device,
        // targets this branch (device_id null), or has no targets (= everywhere).
        $servedToDevice = MarketingSlider::query()
            ->whereKey($item->slider_id)
            ->where(function (Builder $q) use ($device): void {
                $q->whereHas('targets', function (Builder $t) use ($device): void {
                    $t->where('device_id', $device->id)
                        ->orWhere(function (Builder $w) use ($device): void {
                            $w->whereNull('device_id')->where('branch_id', $device->branch_id);
                        });
                })->orWhereDoesntHave('targets');
            })
            ->exists();
        if (! $servedToDevice) {
            throw new RuntimeException('slider.display references a slider not served to this device');
        }

        $intOrNull = static fn (string $key): ?int => isset($payload[$key]) ? (int) $payload[$key] : null;

        $impression = MarketingImpression::query()->updateOrCreate(
            [
                'device_id' => $device->getKey(),
                'client_event_id' => $event->client_event_id,
            ],
            [
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'slider_id' => (int) $item->slider_id,
                'slider_item_id' => (int) $item->id,
                // RE-DERIVED from the resolved row, NOT the payload.
                'content_asset_id' => (int) $item->content_asset_id,
                'advertiser_id' => $item->advertiser_id !== null
                    ? (int) $item->advertiser_id
                    : null,
                'play_duration_ms' => (int) $payload['duration_ms'],
                'viewers_peak' => $intOrNull('viewers_peak'),
                'viewers_avg' => $intOrNull('viewers_avg'),
                'viewers_distinct' => $intOrNull('viewers_distinct'),
                'attention_ms' => $intOrNull('attention_ms'),
                'played_at' => $playedAt,
            ],
        );

        return [
            'impression_id' => (int) $impression->id,
            'play_duration_ms' => (int) $impression->play_duration_ms,
        ];
    }
}
