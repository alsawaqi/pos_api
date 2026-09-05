<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Cursor-paginated accepted-round feed for durable kitchen auto-printing. */
final class ListAcceptedDineInQrRoundsAction
{
    private const HORIZON_HOURS = 6;

    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 50;

    public function __construct(private readonly QrChargeRecoveryGuard $recovery) {}

    /**
     * @return array{
     *   rounds: list<array<string, mixed>>,
     *   next_cursor: string|null,
     *   latest_cursor: string,
     *   skipped_expired_count: int
     * }
     */
    public function handle(
        Device $device,
        ?string $after = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        if (! $this->recovery->isAttendedDevice($device)) {
            throw new QrDineInException(
                'device_not_attended',
                409,
                'Only an attended fixed POS or handheld device may read accepted QR rounds.',
            );
        }

        $limit = min(self::MAX_LIMIT, max(1, $limit));
        $horizon = now()->subHours(self::HORIZON_HOURS);
        $accepted = $this->baseQuery($device);
        $latest = (clone $accepted)
            ->orderByDesc('pos_qr_order_rounds.accepted_seq')
            ->first();

        $afterSequence = null;
        if ($after !== null && trim($after) !== '') {
            $afterSequence = $this->decodeCursor($after, $device);
        }

        $skippedExpiredCount = 0;
        if ($afterSequence !== null) {
            $skippedExpiredCount = (clone $accepted)
                ->where('pos_qr_order_rounds.accepted_seq', '>', $afterSequence)
                ->where('pos_qr_order_rounds.resolved_at', '<', $horizon)
                ->count();
        }

        $printable = (clone $accepted)
            ->where('pos_qr_order_rounds.resolved_at', '>=', $horizon);
        if ($afterSequence !== null) {
            $printable->where('pos_qr_order_rounds.accepted_seq', '>', $afterSequence);
        }

        $rounds = $printable
            ->orderBy('pos_qr_order_rounds.accepted_seq')
            ->limit($limit)
            ->get();
        $tickets = DB::table('pos_kitchen_tickets')
            ->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id)
            ->whereIn('round_id', $rounds->modelKeys())
            ->get()->keyBy('ticket_key');

        // Even a branch with no accepted rounds needs a durable high-water
        // mark. Without this scoped sequence-zero cursor, a till first enabled
        // on an empty branch has no `after` value; if its first acceptance is
        // then missed beyond the print horizon, the server cannot report or
        // retire that expired gap.
        $latestCursor = $this->encodeCursor(
            $latest instanceof QrOrderRound ? (int) $latest->accepted_seq : 0,
            $device,
        );
        $lastRound = $rounds->last();
        $nextCursor = $lastRound instanceof QrOrderRound
            ? ((int) $lastRound->accepted_seq === (int) $latest?->accepted_seq
                ? $latestCursor
                : $this->encodeCursor((int) $lastRound->accepted_seq, $device))
            : null;

        return [
            'rounds' => $rounds->map(fn (QrOrderRound $round): array => $this->present($round, $tickets->get('round:'.$round->id)))->all(),
            'next_cursor' => $nextCursor,
            'latest_cursor' => $latestCursor,
            'skipped_expired_count' => $skippedExpiredCount,
        ];
    }

    /** @return Builder<QrOrderRound> */
    private function baseQuery(Device $device): Builder
    {
        return QrOrderRound::query()
            ->select([
                'pos_qr_order_rounds.id',
                'pos_qr_order_rounds.round_no',
                'pos_qr_order_rounds.status',
                'pos_qr_order_rounds.priced_lines',
                'pos_qr_order_rounds.accepted_seq',
                'pos_qr_order_rounds.subtotal_baisas',
                'pos_qr_order_rounds.tax_baisas',
                'pos_qr_order_rounds.total_baisas',
                'pos_qr_order_rounds.submitted_at',
                'pos_qr_order_rounds.resolved_at',
                'pos_qr_order_rounds.needs_review',
                'pos_qr_order_rounds.kitchen_printed_at',
                'pos_orders.id as feed_order_id',
                'pos_orders.source as feed_source',
                'pos_orders.uuid as feed_order_uuid',
                'pos_orders.receipt_number as feed_receipt_number',
                'pos_orders.temp_reference as feed_temp_reference',
                'pos_qr_sessions.uuid as feed_session_uuid',
                'pos_table_sessions.uuid as feed_table_session_uuid',
                'pos_tables.label as feed_table_label',
            ])
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_qr_order_rounds.order_id')
            ->leftJoin('pos_qr_sessions', 'pos_qr_sessions.id', '=', 'pos_qr_order_rounds.qr_session_id')
            ->leftJoin('pos_table_sessions', 'pos_table_sessions.id', '=', 'pos_qr_order_rounds.table_session_id')
            ->leftJoin('pos_tables', 'pos_tables.id', '=', DB::raw('COALESCE(pos_qr_sessions.table_id, pos_table_sessions.table_id)'))
            ->where('pos_qr_order_rounds.status', QrOrderRound::STATUS_ACCEPTED)
            ->where('pos_qr_order_rounds.needs_review', false)
            ->whereNotNull('pos_qr_order_rounds.accepted_seq')
            ->where('pos_orders.company_id', (int) $device->company_id)
            ->where('pos_orders.branch_id', (int) $device->branch_id)
            ->whereIn('pos_orders.source', [Order::SOURCE_QR_WEB, 'main_pos', 'handheld'])
            ->where('pos_orders.order_type', 'dine_in')
            ->where(function (Builder $query): void {
                $query->whereNotNull('pos_qr_order_rounds.qr_session_id')
                    ->orWhereNotNull('pos_qr_order_rounds.table_session_id');
            })
            ->where(function (Builder $query) use ($device): void {
                $query->whereNull('pos_qr_order_rounds.qr_session_id')
                    ->orWhere(function (Builder $credential) use ($device): void {
                        $credential->where('pos_qr_sessions.company_id', (int) $device->company_id)
                            ->where('pos_qr_sessions.branch_id', (int) $device->branch_id);
                    });
            })
            ->where(function (Builder $query) use ($device): void {
                $query->whereNull('pos_qr_order_rounds.table_session_id')
                    ->orWhere(function (Builder $seating) use ($device): void {
                        $seating->where('pos_table_sessions.company_id', (int) $device->company_id)
                            ->where('pos_table_sessions.branch_id', (int) $device->branch_id);
                    });
            });
    }

    /** @return array<string, mixed> */
    private function present(QrOrderRound $round, ?object $ticket): array
    {
        if ($ticket !== null && ((int) $ticket->round_id !== (int) $round->id
            || (int) $ticket->order_id !== (int) $round->feed_order_id)) {
            $ticket = null;
        }
        $printedValue = $ticket?->printed_at ?? $round->kitchen_printed_at;
        $printedAt = $printedValue === null ? null : Carbon::parse($printedValue);

        return [
            'id' => (int) $round->id,
            'round_no' => (int) $round->round_no,
            'priced_lines' => $round->priced_lines,
            'subtotal_baisas' => (int) $round->subtotal_baisas,
            'tax_baisas' => (int) $round->tax_baisas,
            'total_baisas' => (int) $round->total_baisas,
            'submitted_at' => $round->submitted_at?->toIso8601String(),
            'resolved_at' => $round->resolved_at?->toIso8601String(),
            'table_label' => is_string($round->feed_table_label)
                ? $round->feed_table_label
                : null,
            'receipt_number' => is_string($round->feed_receipt_number)
                ? $round->feed_receipt_number
                : null,
            'temp_reference' => is_string($round->feed_temp_reference)
                ? $round->feed_temp_reference
                : null,
            'order_uuid' => (string) $round->feed_order_uuid,
            'session_uuid' => is_string($round->feed_session_uuid) ? $round->feed_session_uuid : null,
            'table_session_uuid' => is_string($round->feed_table_session_uuid) ? $round->feed_table_session_uuid : null,
            'ticket_key' => 'round:'.$round->id,
            'claimed_by_device_id' => $ticket?->claimed_by_device_id === null ? null : (int) $ticket->claimed_by_device_id,
            'printed_at' => $printedAt?->toIso8601String(),
            'needs_review' => (bool) $round->needs_review,
            'source' => (string) $round->feed_source,
        ];
    }

    private function encodeCursor(int $acceptedSequence, Device $device): string
    {
        return Crypt::encryptString(json_encode([
            'accepted_seq' => $acceptedSequence,
            'company_id' => (int) $device->company_id,
            'branch_id' => (int) $device->branch_id,
        ], JSON_THROW_ON_ERROR));
    }

    private function decodeCursor(string $cursor, Device $device): int
    {
        try {
            $payload = json_decode(
                Crypt::decryptString(trim($cursor)),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            if (! is_array($payload)
                || ! is_int($payload['accepted_seq'] ?? null)
                || $payload['accepted_seq'] < 0
                || ($payload['company_id'] ?? null) !== (int) $device->company_id
                || ($payload['branch_id'] ?? null) !== (int) $device->branch_id) {
                throw new \UnexpectedValueException('Invalid cursor payload.');
            }

            return $payload['accepted_seq'];
        } catch (Throwable) {
            throw new QrDineInException(
                'validation_failed',
                422,
                'The accepted-round cursor was invalid.',
            );
        }
    }
}
