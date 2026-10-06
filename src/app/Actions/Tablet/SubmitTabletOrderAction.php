<?php

declare(strict_types=1);

namespace App\Actions\Tablet;

use App\Actions\Qr\AllocateQrTempReferenceAction;
use App\Actions\Qr\AppendQrPricedLinesAction;
use App\Actions\Qr\FreezeQrRoundLinesAction;
use App\Actions\Qr\LoadQrPricingInputAction;
use App\Actions\Qr\QrCatalogueException;
use App\Actions\Qr\QrPricingLoadResult;
use App\Actions\Qr\ResolveQrCustomerAction;
use App\Actions\Tables\AppendTableSessionEventAction;
use App\Actions\Tables\EnsureLegacyTableBillBaselineAction;
use App\Actions\Tables\ListTableBoardAction;
use App\Actions\Tables\TableLoyaltyDiscount;
use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TabletOrder;
use App\Models\TabletOrderEvent;
use App\Support\Catalogue\CookingTime;
use App\Support\Money;
use App\Support\Pricing\BillMoney;
use App\Support\Pricing\Totals;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * LAUNCH-P6 Part A item 2 — `POST device/tablet/orders`: one customer tablet
 * submit, priced by the server (the QR pricer, snapshot writers, temporary
 * reference allocator, customer resolver and table seating rules) without a
 * QR credential or a payment station. Every tablet order waits for staff:
 *
 *  - Quick order / To go: a `held` order, source `customer_tablet`,
 *    order_type `quick` / `to_go`, with a temporary reference (its number is
 *    the "order number"); no receipt number until it is paid.
 *  - Dine in: a `pending_confirmation` round on the table's live bill (a
 *    second round joins it, the bill's source is never rewritten — tester
 *    call 14); a table with no live seating gets a new one and a new bill
 *    with source `customer_tablet`. Staff confirming the round = sending it
 *    to the kitchen.
 *
 * The repeat of a submit key (`client_uuid`) on the same tablet returns the
 * first result (tester call 16). A line that is sold out or not available
 * is refused with every such line named (409 tablet_lines_unavailable).
 */
final class SubmitTabletOrderAction
{
    private const SNAPSHOT_CHANGED = 'tablet table bill changed while acquiring locks';

    public function __construct(
        private readonly LoadQrPricingInputAction $pricing,
        private readonly ResolveQrCustomerAction $customers,
        private readonly AllocateQrTempReferenceAction $references,
        private readonly AppendQrPricedLinesAction $append,
        private readonly FreezeQrRoundLinesAction $freeze,
        private readonly AppendTableSessionEventAction $journal,
        private readonly EnsureLegacyTableBillBaselineAction $baseline,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  validated (TabletOrderRequest)
     * @return array{tablet: TabletOrder, replayed: bool}
     */
    public function handle(Device $device, array $payload): array
    {
        $phone = null;
        if (is_string($payload['phone'] ?? null) && trim($payload['phone']) !== '') {
            $phone = TabletLoyalty::phone($payload['phone']);
            if ($phone === null) {
                throw new TabletOrderException('phone_invalid', 422, 'Enter an Omani phone number.');
            }
        }
        $redeem = is_array($payload['redeem_request'] ?? null) ? $payload['redeem_request'] : null;
        if ($redeem !== null && $phone === null) {
            throw new TabletOrderException('redeem_needs_phone', 422, 'Enter a phone number to use points.');
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn (): array => $this->submit($device, $payload, $phone, $redeem), 5);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== self::SNAPSHOT_CHANGED || $attempt >= 4) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{rule_id: int, blocks: int}|null  $redeem
     * @return array{tablet: TabletOrder, replayed: bool}
     */
    private function submit(Device $device, array $payload, ?string $phone, ?array $redeem): array
    {
        // Serialises this tablet's submits: a double tap waits here and then
        // finds the first one's row.
        $tablet = Device::query()->whereKey($device->id)->lockForUpdate()->first();
        if ($tablet === null || $tablet->device_type !== 'customer_tablet' || $tablet->status !== 'active' || ! $tablet->isAssigned()
            || (int) $tablet->company_id !== (int) $device->company_id || (int) $tablet->branch_id !== (int) $device->branch_id) {
            throw new TabletOrderException('device_not_tablet', 403, 'Only an active customer tablet can do this.');
        }
        $companyId = (int) $tablet->company_id;
        $branchId = (int) $tablet->branch_id;
        $clientUuid = (string) $payload['client_uuid'];
        $existing = TabletOrder::query()->where('device_id', $tablet->id)->where('client_uuid', $clientUuid)->first();
        if ($existing !== null) {
            return ['tablet' => $existing, 'replayed' => true];
        }

        $type = (string) $payload['order_type'];
        $lines = self::withoutNotes($payload['lines']);
        $now = now();
        $at = DateTimeImmutable::createFromInterface($now);
        $classified = $this->pricing->classify($companyId, $branchId, $lines, $at, false, $type);
        if ($classified['held'] !== []) {
            throw new TabletOrderException('tablet_lines_unavailable', 409, 'Some items are sold out or not available.',
                ['lines' => array_map(static fn (array $line): array => [
                    'line_index' => (int) $line['line_index'], 'product_id' => (int) $line['product_id'],
                    'addon_id' => $line['addon_id'] === null ? null : (int) $line['addon_id'], 'reason' => (string) $line['reason'],
                ], $classified['held'])]);
        }

        // Customer before the table graph (the table adjustment's lock order).
        $customerId = $phone === null ? null : $this->customers->handle($companyId, $phone, null)->customerId;

        return $type === 'dine_in'
            ? $this->dineIn($tablet, $payload, $lines, $customerId, $redeem, $now, $at)
            : $this->counter($tablet, $payload, $type, $lines, $customerId, $redeem, $now, $at);
    }

    /**
     * Quick order / To go: a held order of its own.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array{rule_id: int, blocks: int}|null  $redeem
     * @return array{tablet: TabletOrder, replayed: bool}
     */
    private function counter(Device $tablet, array $payload, string $type, array $lines, ?int $customerId, ?array $redeem,
        CarbonInterface $now, DateTimeImmutable $at): array
    {
        $companyId = (int) $tablet->company_id;
        $branchId = (int) $tablet->branch_id;
        $loaded = $this->priced($companyId, $branchId, $lines, $at, null, $type);
        $price = Totals::priceOrder($loaded->pricingInput);
        $this->assertRedeemable($companyId, $customerId, $redeem,
            BillMoney::net($price->grandTotalBaisas, $price->taxTotalBaisas, $price->pricesIncludeTax));

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'device_id' => $tablet->id,
            'qr_session_id' => null,
            'client_request_id' => (string) $payload['client_uuid'],
            'staff_id' => null,
            'customer_id' => $customerId,
            'table_id' => null,
            'order_type' => $type,
            'status' => Order::STATUS_HELD,
            'source' => 'customer_tablet',
            'subtotal' => Money::toOmr($price->rawSubtotalBaisas),
            'discount_total' => Money::toOmr($price->discountTotalBaisas),
            'comp_total' => Money::toOmr(0),
            'tax_total' => Money::toOmr($price->taxTotalBaisas),
            'grand_total' => Money::toOmr($price->grandTotalBaisas),
            'prices_include_tax' => $price->pricesIncludeTax,
            'opened_at' => $now,
            'closed_at' => null,
            'client_event_id' => null,
            'receipt_number' => null,
            'temp_reference' => $this->references->handle($companyId, $branchId),
        ]);
        // Existing pricing helpers read only company_id from this typed
        // context. It is never saved: a tablet order has NO QR credential.
        $itemIds = $this->append->handle($order, new QrSession(['company_id' => $companyId]), $loaded, $price, $now);
        $kitchenLines = $this->freeze->handle($loaded, $price);
        foreach ($kitchenLines as $index => &$line) {
            $line['order_item_id'] = $itemIds[$index];
        }
        unset($line);

        $row = $this->row($tablet, $payload, $order, null, null, $type, $customerId, $redeem, [
            'ready_in_minutes' => CookingTime::readyInForOrder((int) $order->id),
            'subtotal_baisas' => $price->rawSubtotalBaisas, 'tax_baisas' => $price->taxTotalBaisas,
            'total_baisas' => $price->grandTotalBaisas, 'kitchen_lines' => $kitchenLines,
        ], $now);

        return ['tablet' => $row, 'replayed' => false];
    }

    /**
     * Dine in: a pending round on the table's live bill, or a new seating and
     * bill. The branch's table graph is locked like a staff seating write:
     * tables -> orders -> credentials -> seatings.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array{rule_id: int, blocks: int}|null  $redeem
     * @return array{tablet: TabletOrder, replayed: bool}
     */
    private function dineIn(Device $tablet, array $payload, array $lines, ?int $customerId, ?array $redeem,
        CarbonInterface $now, DateTimeImmutable $at): array
    {
        $companyId = (int) $tablet->company_id;
        $branchId = (int) $tablet->branch_id;
        $floors = DB::table('pos_floors')->select('id')->where('company_id', $companyId)->where('branch_id', $branchId)->whereNull('deleted_at');
        $tables = Table::query()->withTrashed()->where('company_id', $companyId)
            ->whereIn('floor_id', DB::table('pos_floors')->select('id')->where('company_id', $companyId)->where('branch_id', $branchId))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $table = Table::query()->where('company_id', $companyId)->where('uuid', (string) $payload['table_uuid'])
            ->whereIn('floor_id', $floors)->first();
        if ($table === null || $table->status !== 'active' || ! $tables->has($table->id)) {
            throw new TabletOrderException('table_not_found', 404, 'The table was not found in this branch.');
        }

        $orderScope = Order::query()->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('order_type', 'dine_in')->whereNotNull('table_id')
            ->where(function (Builder $query) use ($companyId, $branchId): void {
                $query->whereIn('status', ListTableBoardAction::UNPAID_STATUSES)
                    ->orWhereIn('id', TableSession::query()->select('order_id')->where('company_id', $companyId)
                        ->where('branch_id', $branchId)->whereIn('status', TableSession::LIVE_STATUSES)->whereNotNull('order_id'));
            });
        $orders = (clone $orderScope)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        QrSession::query()->where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereNotNull('table_id')->orderBy('id')->lockForUpdate()->get();
        if ((clone $orderScope)->pluck('id')->diff($orders->keys())->isNotEmpty()) {
            throw new RuntimeException(self::SNAPSHOT_CHANGED);
        }
        $scope = TableSession::query()->where('company_id', $companyId)->where('branch_id', $branchId);
        $seatings = (clone $scope)->whereNull('merged_into_id')->orderBy('id')->lockForUpdate()->get()
            ->concat((clone $scope)->whereNotNull('merged_into_id')->orderBy('id')->lockForUpdate()->get())->keyBy('id');

        $live = $seatings->first(static fn (TableSession $seat): bool => (int) $seat->table_id === (int) $table->id
            && in_array($seat->status, TableSession::LIVE_STATUSES, true));
        $primary = $live === null ? null : ($live->merged_into_id === null ? $live : $seatings->get((int) $live->merged_into_id));
        if ($live !== null && ($primary === null || ! in_array($primary->status, TableSession::LIVE_STATUSES, true))) {
            throw new TabletOrderException('table_bill_not_open', 409, 'Please order with staff.');
        }
        $order = $primary?->order_id === null ? null : $orders->get((int) $primary->order_id);
        if ($primary !== null && $primary->order_id !== null && $order === null) {
            throw new RuntimeException(self::SNAPSHOT_CHANGED);
        }
        if ($primary === null) {
            // An unpaid bill left at the table without a live seating is not a
            // party the tablet may join or replace.
            $orphan = $orders->first(static fn (Order $bill): bool => (int) $bill->table_id === (int) $table->id
                && in_array($bill->status, ListTableBoardAction::UNPAID_STATUSES, true));
            if ($orphan !== null) {
                throw new TabletOrderException('table_bill_not_open', 409, 'Please order with staff.');
            }
        }
        if (($primary !== null && ($primary->status !== TableSession::STATUS_OPEN || $primary->billing_at !== null))
            || ($order !== null && $order->status !== Order::STATUS_OPEN)) {
            throw new TabletOrderException('table_bill_not_open', 409, 'Please order with staff.');
        }
        if ($order !== null) {
            $this->baseline->handle($order);
        }

        // A further round is priced in the bill's own tax mode.
        $loaded = $this->priced($companyId, $branchId, $lines, $at, $order !== null ? (bool) $order->prices_include_tax : null, 'dine_in');
        $price = Totals::priceOrder($loaded->pricingInput);
        $this->assertRedeemable($companyId, $customerId, $redeem,
            BillMoney::net($price->grandTotalBaisas, $price->taxTotalBaisas, $price->pricesIncludeTax));

        if ($primary === null) {
            $primary = TableSession::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId,
                'table_id' => (int) $table->id, 'origin' => 'customer_tablet', 'opened_by_device_id' => $tablet->id,
                // A staff device addresses this seating by its key (a uuid).
                'client_request_id' => (string) Str::uuid(), 'status' => TableSession::STATUS_OPEN,
                'opened_at' => $now, 'expires_at' => $now->copy()->addHours(6), 'order_id' => null,
                'temp_reference' => $this->references->handle($companyId, $branchId),
            ]);
            $seatings->put((int) $primary->id, $primary);
            $this->journal->handle($primary, 'opened', ['outcome' => 'opened', 'origin' => 'customer_tablet'], (int) $tablet->id, $now);
        }
        if ($order === null) {
            $order = Order::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId,
                'device_id' => $tablet->id, 'qr_session_id' => null, 'client_request_id' => null, 'client_event_id' => null,
                'staff_id' => null, 'customer_id' => $customerId, 'table_id' => (int) $primary->table_id,
                'table_session_id' => (int) $primary->id, 'order_type' => 'dine_in', 'status' => Order::STATUS_OPEN,
                'source' => 'customer_tablet', 'subtotal' => Money::toOmr(0), 'discount_total' => Money::toOmr(0),
                'comp_total' => Money::toOmr(0), 'tax_total' => Money::toOmr(0), 'grand_total' => Money::toOmr(0),
                'prices_include_tax' => $price->pricesIncludeTax,
                'opened_at' => $now, 'temp_reference' => $primary->temp_reference, 'receipt_number' => null,
            ]);
            $primary->update(['order_id' => $order->id]);
            foreach ($seatings as $joined) {
                if ((int) $joined->merged_into_id === (int) $primary->id && in_array($joined->status, TableSession::LIVE_STATUSES, true)) {
                    $joined->update(['order_id' => $order->id]);
                    DB::table('pos_order_tables')->insertOrIgnore(['order_id' => $order->id, 'table_id' => $joined->table_id]);
                }
            }
        } elseif ($customerId !== null && $order->customer_id === null) {
            // The typed phone links a bill that has no customer yet. A bill
            // that already names a customer keeps it (staff own that choice).
            $order->update(['customer_id' => $customerId]);
        }

        $confirmPayload = $this->append->buildPayload(new QrSession(['company_id' => $companyId]), $loaded, $price, $now);
        $round = QrOrderRound::query()->create([
            'qr_session_id' => null,
            'table_session_id' => (int) $primary->id,
            'order_id' => (int) $order->id,
            'round_no' => (int) QrOrderRound::query()->where('order_id', $order->id)->max('round_no') + 1,
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
            'client_request_id' => (string) $payload['client_uuid'],
            'priced_lines' => $this->freeze->handle($loaded, $price),
            'confirm_payload' => $confirmPayload,
            'accepted_seq' => null,
            'needs_review' => false,
            'subtotal_baisas' => $price->rawSubtotalBaisas,
            'tax_baisas' => $price->taxTotalBaisas,
            'total_baisas' => $price->grandTotalBaisas,
            'submitted_at' => $now,
            'resolved_at' => null,
            'resolved_by_device_id' => null,
        ]);

        $row = $this->row($tablet, $payload, $order, $round, (int) $table->id, 'dine_in', $customerId, $redeem, [
            'ready_in_minutes' => CookingTime::longest(array_map(static fn (array $item): mixed => $item['attributes']['cooking_minutes'] ?? null,
                $confirmPayload['items'])),
            'subtotal_baisas' => $price->rawSubtotalBaisas, 'tax_baisas' => $price->taxTotalBaisas,
            'total_baisas' => $price->grandTotalBaisas, 'kitchen_lines' => null,
        ], $now);

        $event = ['round_id' => (int) $round->id, 'order_uuid' => (string) $order->uuid,
            'origin' => 'customer_tablet', 'tablet_order_uuid' => (string) $row->uuid];
        $this->journal->handle($primary, 'round_pending', $event + ['optional_identity' => true,
            'customer_identity_set' => $order->fresh()->customer_id !== null], (int) $tablet->id, $now);
        $this->journal->handle($primary, 'customer_order_arrived', $event, (int) $tablet->id, $now);
        $this->journal->flush();

        return ['tablet' => $row, 'replayed' => false];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function priced(int $companyId, int $branchId, array $lines, DateTimeImmutable $at, ?bool $inclusive, string $type): QrPricingLoadResult
    {
        try {
            return $this->pricing->handle($companyId, $branchId, $lines, $at, $inclusive, $type);
        } catch (QrCatalogueException $exception) {
            // A catalogue change between the check and the price.
            throw new TabletOrderException('tablet_lines_unavailable', 409, 'Some items are sold out or not available.',
                ['lines' => [], 'reason' => $exception->codeName]);
        }
    }

    /**
     * The request names a reward the customer can afford now, worth less than
     * this submit (staff approve it later under every limit).
     *
     * @param  array{rule_id: int, blocks: int}|null  $redeem
     */
    private function assertRedeemable(int $companyId, ?int $customerId, ?array $redeem, int $netBaisas): void
    {
        if ($redeem === null) {
            return;
        }
        $reward = TabletLoyalty::reward($companyId, (int) $redeem['rule_id']);
        $account = $reward === null || $customerId === null ? null : LoyaltyAccount::query()->where('company_id', $companyId)
            ->where('customer_id', $customerId)->where('loyalty_rule_id', (int) $redeem['rule_id'])->first();
        if ($reward === null || $account === null) {
            throw new TabletOrderException('redeem_not_available', 409, 'These points cannot be used.');
        }
        $balance = (int) ($reward['kind'] === 'stamps' ? $account->stamp_count : $account->point_balance);
        $available = $balance - TableLoyaltyDiscount::pendingUnits($companyId, $customerId, (int) $redeem['rule_id'], $reward['kind']);
        if ($available < $reward['unit'] * (int) $redeem['blocks']) {
            throw new TabletOrderException('redeem_not_available', 409, 'There are not enough points for this.');
        }
        if ($netBaisas <= $reward['value_baisas'] * (int) $redeem['blocks']) {
            throw new TabletOrderException('redeem_exceeds_order', 409, 'The points must leave something to pay.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{rule_id: int, blocks: int}|null  $redeem
     * @param  array<string, mixed>  $money
     */
    private function row(Device $tablet, array $payload, Order $order, ?QrOrderRound $round, ?int $tableId, string $type,
        ?int $customerId, ?array $redeem, array $money, CarbonInterface $now): TabletOrder
    {
        $row = TabletOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => (int) $tablet->company_id,
            'branch_id' => (int) $tablet->branch_id,
            'device_id' => (int) $tablet->id,
            'client_uuid' => (string) $payload['client_uuid'],
            'order_id' => (int) $order->id,
            'round_id' => $round?->id,
            'table_id' => $tableId,
            'order_type' => $type,
            'payment_choice' => (string) $payload['payment'],
            'customer_id' => $customerId,
            'redeem_status' => $redeem === null ? null : TabletOrder::REDEEM_REQUESTED,
            'redeem_rule_id' => $redeem === null ? null : (int) $redeem['rule_id'],
            'redeem_blocks' => $redeem === null ? null : (int) $redeem['blocks'],
            'submitted_at' => $now,
        ] + $money);
        TabletOrderEvent::record($row, 'submitted', null, (int) $tablet->id, [
            'order_uuid' => (string) $order->uuid, 'round_id' => $round?->id, 'order_type' => $type,
            'redeem_requested' => $redeem !== null,
        ]);

        return $row;
    }

    /**
     * Tester call 5 — the customer's note is the tap lists only: no free text
     * reaches a kitchen ticket (the request already refuses a non-empty one).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function withoutNotes(array $lines): array
    {
        return array_map(static function (array $line): array {
            $line['notes'] = null;
            $line['addon_ids'] ??= [];
            if (is_array($line['combo'] ?? null)) {
                $line['combo'] = array_map(static fn (array $pick): array => ['notes' => null] + $pick + ['addon_ids' => []], $line['combo']);
                foreach ($line['combo'] as &$pick) {
                    $pick['notes'] = null;
                }
                unset($pick);
            }

            return $line;
        }, array_values($lines));
    }
}
