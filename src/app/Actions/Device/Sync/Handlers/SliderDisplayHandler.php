<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\MarketingImpression;
use App\Models\MarketingSlider;
use App\Models\MarketingSliderItem;
use App\Models\SyncEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Phase 3 — records one advertising-slide impression (play-time) from a
 * `slider.display` sync event into pos_marketing_impressions, scoped to the
 * reporting device's company + branch.
 *
 * Idempotent on the event's client_event_id: the ingest layer already dedups a
 * replayed batch before dispatch, and the firstOrNew on
 * (device_id, client_event_id) is the backstop so a re-processed FAILED event
 * never double-counts a play. Every column billing meters off is written only
 * on first sight — a replay may refine audience telemetry, never the money.
 */
class SliderDisplayHandler implements SyncEventHandler
{
    /**
     * How far a device-reported play time may sit from server receipt before
     * we stop believing it.
     *
     * Deliberately small. Unlike order.* events, `slider.display` is NOT
     * durably queued on the device — pos_machine mints played_at microseconds
     * before a single fire-and-forget push and drops the event on any
     * transport failure (pos_machine order_sync_repository.dart), and
     * pos_handheld does not emit it at all. So the only legitimate gap is one
     * HTTP round trip; this window merely absorbs clock skew and a slow link.
     */
    private const REPORT_TOLERANCE_MINUTES = 10;

    /**
     * A physical screen cannot play more slides in a day than this — see
     * services.marketing.max_plays_per_device_per_day for the rationale and
     * the env override.
     */
    private function maxPlaysPerDevicePerDay(): int
    {
        return (int) config('services.marketing.max_plays_per_device_per_day', 25000);
    }

    /**
     * Decide the billing anchor for a reported play.
     *
     * played_at is the column advertiser invoices are metered on — screen-days
     * are COUNT(DISTINCT device@DATE(played_at)) and CPM is COUNT(*) — so it
     * must not be device-chosen. We keep the device's own time when it is
     * close to receipt (it is more precise, and matters at a day boundary) and
     * otherwise stamp server time.
     *
     * Note what this deliberately does NOT do: it never anchors an implausible
     * report to some far horizon. Materialising a play at a date the device
     * did not report would CREATE a billable screen-day that never happened —
     * a device with a reset clock reporting the year 2000 would otherwise
     * accrue a fresh billable day on every push. Server time is the honest
     * answer: it is when we actually heard about it.
     */
    private function resolvePlayedAt(?CarbonInterface $reported): Carbon
    {
        $now = Carbon::now();

        if ($reported === null) {
            return $now;
        }

        // UTC-normalise FIRST. Carbon keeps whatever offset the payload
        // declared, and Eloquent writes the wall-clock reading of that zone
        // into a timezone-less column — so a +23:59 offset would shift the
        // stored DATE by a day and slip straight past every check below.
        $candidate = Carbon::parse($reported)->utc();

        return $candidate->diffInMinutes($now, absolute: true) <= self::REPORT_TOLERANCE_MINUTES
            ? $candidate
            : $now;
    }

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
            // Upper bounds are load-bearing, not tidiness. play_duration_ms is
            // SUMmed into the invoice's play_seconds and shown to the
            // advertiser per branch/day, and the columns are unsignedInteger —
            // which Postgres renders SIGNED, so a value past 2^31-1 passes
            // validation and then dies at INSERT. A failed sync event is
            // re-dispatched on every later push, so that row would wedge the
            // device's sync forever. One hour is far beyond any real slide.
            'duration_ms' => ['required', 'integer', 'min:1', 'max:3600000'],
            'played_at' => ['sometimes', 'nullable', 'date'],
            // Anonymous audience measurement (optional; only sent when the
            // device's camera-based counter is enabled). Aggregate counts only.
            'viewers_peak' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'viewers_avg' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'viewers_distinct' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'attention_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3600000'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('invalid slider.display payload: '.implode('; ', $validator->errors()->all()));
        }

        // BILLING INTEGRITY — played_at is what advertiser invoices are metered
        // on. Taken raw from the device it let one terminal spread fabricated
        // plays over any number of dates and backdate them into an unbilled
        // period; the (device_id, client_event_id) replay guard is no defence,
        // since fresh uuids satisfy it.
        $playedAt = $this->resolvePlayedAt(
            $payload['played_at'] ?? null
                ? Carbon::parse((string) $payload['played_at'])
                : $event->client_timestamp,
        );

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
        // told to play, AT THE MOMENT IT PLAYED". Both halves come from the
        // shared scopes the device-config slice uses, so the two cannot drift
        // — this gate previously copied only the targeting half, letting draft,
        // paused and long-ended campaigns accrue billable impressions.
        //
        // liveAt($playedAt): judged at play time, which after resolvePlayedAt
        // is within minutes of receipt — so a play reported right as a
        // campaign ends still counts, without opening a backdating window.
        $servedToDevice = MarketingSlider::query()
            ->whereKey($item->slider_id)
            ->liveAt($playedAt)
            ->servedToDevice($device)
            ->exists();
        if (! $servedToDevice) {
            throw new RuntimeException('slider.display references a slider not live on this device at play time');
        }

        $intOrNull = static fn (string $key): ?int => isset($payload[$key]) ? (int) $payload[$key] : null;

        $impression = MarketingImpression::query()->firstOrNew([
            'device_id' => $device->getKey(),
            'client_event_id' => $event->client_event_id,
        ]);

        if (! $impression->exists) {
            // A screen can only play so much in a day. Without this the CPM
            // model (billed on raw COUNT(*)) is bounded only by the sync
            // throttle — thousands of fabricated plays a minute, aimed at any
            // advertiser whose slider is untargeted and so reachable from
            // every device on the platform.
            $playsToday = MarketingImpression::query()
                ->where('device_id', $device->getKey())
                ->whereDate('played_at', $playedAt->toDateString())
                ->count();
            if ($playsToday >= $this->maxPlaysPerDevicePerDay()) {
                throw new RuntimeException('slider.display exceeds the plausible daily play count for one screen');
            }

            // Everything billing meters off is written ONCE and never moved by
            // a later replay: played_at decides WHICH period bills the play,
            // advertiser_id decides WHOSE invoice, and play_duration_ms feeds
            // the play_seconds an advertiser is shown. A re-dispatched event
            // carrying different ids could otherwise re-point a play already
            // billed to one advertiser at another — billing it twice.
            $impression->forceFill([
                'played_at' => $playedAt,
                'slider_id' => (int) $item->slider_id,
                'slider_item_id' => (int) $item->id,
                // RE-DERIVED from the resolved row, NOT the payload.
                'content_asset_id' => (int) $item->content_asset_id,
                'advertiser_id' => $item->advertiser_id !== null
                    ? (int) $item->advertiser_id
                    : null,
                'play_duration_ms' => (int) $payload['duration_ms'],
            ]);
        }

        // Non-billing telemetry may be refined by a replay.
        $impression->fill(
            [
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'viewers_peak' => $intOrNull('viewers_peak'),
                'viewers_avg' => $intOrNull('viewers_avg'),
                'viewers_distinct' => $intOrNull('viewers_distinct'),
                'attention_ms' => $intOrNull('attention_ms'),
            ],
        );

        try {
            // The dispatcher owns the outer transaction that couples this
            // billing write to the sync event's processed stamp. PostgreSQL
            // leaves that transaction aborted after a unique violation, so
            // isolate the contested INSERT in a nested transaction/savepoint.
            // The catch below can then safely re-read the concurrent winner.
            DB::transaction(fn () => $impression->save());
        } catch (UniqueConstraintViolationException) {
            // Two concurrent re-pushes of the same failed event both saw "not
            // exists". The winner's row is authoritative — re-read it rather
            // than failing the ACK (updateOrCreate used to absorb this).
            $impression = MarketingImpression::query()
                ->where('device_id', $device->getKey())
                ->where('client_event_id', $event->client_event_id)
                ->firstOrFail();
        }

        return [
            'impression_id' => (int) $impression->id,
            'play_duration_ms' => (int) $impression->play_duration_ms,
        ];
    }
}
