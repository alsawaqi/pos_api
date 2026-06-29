<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\MarketingImpression;
use App\Models\SyncEvent;
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

        $intOrNull = static fn (string $key): ?int => isset($payload[$key]) ? (int) $payload[$key] : null;

        $impression = MarketingImpression::query()->updateOrCreate(
            [
                'device_id' => $device->getKey(),
                'client_event_id' => $event->client_event_id,
            ],
            [
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'slider_id' => (int) $payload['slider_id'],
                'slider_item_id' => (int) $payload['slider_item_id'],
                'content_asset_id' => (int) $payload['content_asset_id'],
                'advertiser_id' => isset($payload['advertiser_id'])
                    ? (int) $payload['advertiser_id']
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
