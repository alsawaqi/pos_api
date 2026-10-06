<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\ListTableBoardAction;
use App\Actions\Tablet\TabletOrderStaffAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;

/** Read-only arrival identities, not a second order or payment projection. */
final class ListOrderAttentionAction
{
    public function __construct(
        private readonly PresentQrPendingOrderAction $pending,
        private readonly ListTableBoardAction $board,
        private readonly TabletOrderStaffAction $tablet,
    ) {}

    /**
     * LAUNCH-P6 (tester call 15) — `tablet_order_keys` (`tablet:<uuid>`, Quick
     * / To go orders and dine-in rounds alike, until someone takes them) is
     * added only for a device that declares `tablet-orders`; an old build's
     * snapshot stays exactly as before.
     *
     * @return array{version: int, quick_order_keys: list<string>, table_round_keys: list<string>, tablet_order_keys?: list<string>}
     */
    public function handle(Device $device, bool $tabletRows = false): array
    {
        $this->pending->assertAttended($device);
        $quick = Order::query()
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->where('source', Order::SOURCE_QR_WEB)
            ->where('order_type', 'quick')->whereNull('table_id')
            ->where(function ($query): void {
                $query->where(function ($counter): void {
                    $counter->where('status', Order::STATUS_HELD);
                    foreach (PresentQrPendingOrderAction::CHARGE_FIELDS as $field) {
                        $counter->whereNull($field);
                    }
                })->orWhere(function ($review): void {
                    $review->whereIn('status', [Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT])
                        ->where('qr_recovery_request->action', 'review');
                });
            });
        // Unlike the inbox's display limit, this snapshot must not silently
        // omit arrivals. No items, money, phone or credential material is read.
        $quickKeys = $quick->orderBy('id')->pluck('uuid')
            ->map(static fn ($uuid): string => 'quick:'.$uuid)->all();

        // Reuse the operational board's exact joined/legacy/retired-table
        // visibility. It is SELECT-only (not the old lazy-expiring QR board).
        $roundIds = collect($this->board->handle($device))
            ->flatMap(static fn (array $row): array => $row['seating']['pending_rounds'] ?? [])
            ->pluck('round_id')->unique()->values()->all();
        $roundKeys = $roundIds === [] ? [] : QrOrderRound::query()
            ->whereIn('id', $roundIds)
            ->where('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
            ->whereHas('session', static fn ($q) => $q
                ->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)->whereNotNull('table_id'))
            ->orderBy('id')->get(['id', 'client_request_id'])
            ->map(static fn (QrOrderRound $round): string => 'round:'.$round->id.':'.$round->client_request_id)
            ->all();

        return ['version' => 1, 'quick_order_keys' => $quickKeys, 'table_round_keys' => $roundKeys]
            + ($tabletRows ? ['tablet_order_keys' => $this->tablet->attentionKeys($device)] : []);
    }
}
