<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
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
            'rounds' => $rounds->map(fn (QrOrderRound $round): array => $this->present($round))->all(),
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
                'pos_orders.uuid as feed_order_uuid',
                'pos_orders.receipt_number as feed_receipt_number',
                'pos_qr_sessions.uuid as feed_session_uuid',
                'pos_tables.label as feed_table_label',
            ])
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_qr_order_rounds.order_id')
            ->join('pos_qr_sessions', 'pos_qr_sessions.id', '=', 'pos_qr_order_rounds.qr_session_id')
            ->leftJoin('pos_tables', 'pos_tables.id', '=', 'pos_qr_sessions.table_id')
            ->where('pos_qr_order_rounds.status', QrOrderRound::STATUS_ACCEPTED)
            ->whereNotNull('pos_qr_order_rounds.accepted_seq')
            ->where('pos_orders.company_id', (int) $device->company_id)
            ->where('pos_orders.branch_id', (int) $device->branch_id)
            ->where('pos_orders.source', Order::SOURCE_QR_WEB)
            ->where('pos_orders.order_type', 'dine_in')
            ->where('pos_qr_sessions.company_id', (int) $device->company_id)
            ->where('pos_qr_sessions.branch_id', (int) $device->branch_id);
    }

    /** @return array<string, mixed> */
    private function present(QrOrderRound $round): array
    {
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
            'order_uuid' => (string) $round->feed_order_uuid,
            'session_uuid' => (string) $round->feed_session_uuid,
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
