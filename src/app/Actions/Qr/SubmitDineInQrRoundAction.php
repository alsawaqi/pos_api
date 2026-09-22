<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Actions\Tables\EnsureLegacyTableBillBaselineAction;
use App\Actions\Tables\TableLoyaltyDiscount;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Support\Money;
use App\Support\Pricing\Totals;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Prices one dine-in round once, freezes it, and appends only its new rows. */
final class SubmitDineInQrRoundAction
{
    private const SNAPSHOT_CHANGED = 'QR seating bill changed while acquiring its locks';

    public function __construct(
        private readonly LoadQrPricingInputAction $pricing,
        private readonly AssertQrStockAvailableAction $stock,
        private readonly ResolveQrCustomerAction $customers,
        private readonly DistinctQrPhoneGuard $phoneGuard,
        private readonly AllocateQrTempReferenceAction $tempReferences,
        private readonly FreezeQrRoundLinesAction $freeze,
        private readonly AppendQrPricedLinesAction $append,
        private readonly DineInRoundMode $roundMode,
        private readonly RefreshQrOrderTotalsAction $refreshTotals,
        private readonly AllocateQrRoundAcceptedSequenceAction $acceptedSequence,
        private readonly EnsureTableSessionForQrSessionAction $seatings,
        private readonly AppendTableSessionEventAction $journal,
        private readonly EnsureLegacyTableBillBaselineAction $baseline,
    ) {}

    /**
     * @param array{
     *   client_request_id: string,
     *   phone?: string,
     *   plate_number?: string|null,
     *   lines: list<array<string, mixed>>
     * } $payload
     * @return array{round: QrOrderRound, order: Order, replayed: bool}
     */
    public function handle(int $sessionId, array $payload, string $ip): array
    {
        for ($attempt = 0; ; $attempt++) {
            $knownOrderId = Order::query()->where('qr_session_id', $sessionId)->latest('id')->value('id');
            if ($knownOrderId === null) {
                $session = QrSession::query()->find($sessionId);
                $seating = $session?->table_session_id === null
                    ? null
                    : TableSession::query()->whereKey((int) $session->table_session_id)
                        ->where('company_id', (int) $session->company_id)
                        ->where('branch_id', (int) $session->branch_id)->first();
                if ($seating?->merged_into_id !== null) {
                    $seating = TableSession::query()->whereKey((int) $seating->merged_into_id)
                        ->where('company_id', (int) $session->company_id)
                        ->where('branch_id', (int) $session->branch_id)->first();
                }
                $knownOrderId = $seating?->order_id;
            }

            try {
                return $this->submit($sessionId, $payload, $ip, $knownOrderId === null ? null : (int) $knownOrderId);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== self::SNAPSHOT_CHANGED || $attempt >= 4) {
                    throw $exception;
                }
            }
        }
    }

    /** @param array<string, mixed> $payload
     * @return array{round: QrOrderRound, order: Order, replayed: bool}
     */
    private function submit(int $sessionId, array $payload, string $ip, ?int $knownOrderId): array
    {
        return DB::transaction(function () use (
            $sessionId,
            $payload,
            $ip,
            $knownOrderId,
        ): array {
            $order = $knownOrderId === null
                ? null
                : Order::query()
                    ->whereKey((int) $knownOrderId)
                    ->lockForUpdate()
                    ->first();
            $session = QrSession::query()->whereKey($sessionId)->lockForUpdate()->first();
            if ($session === null || ! $session->isDineIn()) {
                throw new QrDineInException(
                    'qr_dine_in_session_required',
                    409,
                    'A live dine-in QR session is required.',
                );
            }
            if ($session->released_at !== null) {
                throw new QrDineInException('qr_session_not_found', 404, 'QR session was not found.');
            }
            $credentialOrderId = Order::query()
                ->where('qr_session_id', $session->id)
                ->latest('id')
                ->value('id');
            if ($credentialOrderId !== null && (int) $credentialOrderId !== (int) $order?->id) {
                throw new RuntimeException(self::SNAPSHOT_CHANGED);
            }

            $clientRequestId = (string) $payload['client_request_id'];
            $existing = QrOrderRound::query()
                ->where('qr_session_id', $session->id)
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existing !== null) {
                if ($existing->order_id === null
                    || ! $order instanceof Order
                    || (int) $order->getKey() !== (int) $existing->order_id) {
                    throw new QrDineInException(
                        'qr_round_pending_confirmation',
                        409,
                        'This round is awaiting staff confirmation.',
                    );
                }

                return ['round' => $existing, 'order' => $order, 'replayed' => true];
            }

            if ($session->status !== QrSession::STATUS_ACTIVE) {
                throw new QrDineInException(
                    'qr_round_session_not_active',
                    409,
                    'This dine-in session is not accepting rounds.',
                );
            }

            $device = Device::query()
                ->withTrashed()
                ->whereKey((int) $session->device_id)
                ->lockForUpdate()
                ->first();
            if ($session->device_id === null
                ? ($session->origin !== 'table_card' || $session->table_id === null)
                : ! $this->isUsableOpeningStation($session, $device)) {
                throw new QrDineInException(
                    'qr_session_not_found',
                    404,
                    'QR session was not found.',
                );
            }

            $seating = $this->seatings->handle($session, $order, $device);
            if ($seating->order_id !== null && (int) $seating->order_id !== (int) $order?->id) {
                // A staff writer may create the shared bill while the QR
                // snapshot waits. Retry order-first; never lock it after seating.
                throw new RuntimeException(self::SNAPSHOT_CHANGED);
            }
            // T4 joins can precede the customer's first round. Lock members
            // after the primary and before the defensive reference allocation.
            $joinedSeatings = TableSession::query()
                ->where('company_id', (int) $session->company_id)
                ->where('branch_id', (int) $session->branch_id)
                ->where('merged_into_id', (int) $seating->id)
                ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
                ->orderBy('id')->lockForUpdate()->get();

            if ($order !== null && $order->status === Order::STATUS_OPEN) {
                $this->baseline->handle($order);
            }
            $lastBillRoundNo = $order === null ? null : QrOrderRound::query()
                ->where('order_id', $order->id)
                ->max('round_no');
            $roundNo = ((int) ($lastBillRoundNo ?? QrOrderRound::query()
                ->where('qr_session_id', $session->id)
                ->max('round_no'))) + 1;
            $acceptedRoundExists = QrOrderRound::query()
                ->where('qr_session_id', $session->id)
                ->where('status', QrOrderRound::STATUS_ACCEPTED)
                ->exists();
            $identityAllowed = TableQrIdentity::allowed($session, $order);
            $phonePresent = array_key_exists('phone', $payload)
                && is_string($payload['phone'])
                && trim($payload['phone']) !== '';
            if (! $identityAllowed
                && (array_key_exists('phone', $payload) || array_key_exists('plate_number', $payload))) {
                throw new QrDineInException(
                    'qr_round_identity_already_set',
                    422,
                    'Customer identity is already fixed by an accepted round.',
                );
            }

            if ($order !== null && ($order->status !== Order::STATUS_OPEN
                || ($order->qr_session_id !== null && (int) $order->qr_session_id !== (int) $session->id))) {
                throw new QrDineInException(
                    'qr_round_order_not_open',
                    409,
                    'This dine-in order is not open.',
                );
            }
            if ($acceptedRoundExists && $order === null) {
                throw new QrDineInException(
                    'qr_round_order_not_open',
                    409,
                    'This dine-in order is not open.',
                );
            }

            $now = now();
            $loaded = $this->pricing->handle(
                (int) $session->company_id,
                (int) $session->branch_id,
                $payload['lines'],
                DateTimeImmutable::createFromInterface($now),
            );
            $price = Totals::priceOrder($loaded->pricingInput);
            $this->stock->handle((int) $session->company_id, (int) $session->branch_id, $loaded->resolvedLines);

            $customer = null;
            $plate = $order?->plate_number;
            $identityDiffers = false;
            if ($identityAllowed) {
                $phone = trim((string) ($payload['phone'] ?? ''));
                $plate = isset($payload['plate_number'])
                    ? ResolveQrCustomerAction::normalisePlate((string) $payload['plate_number'])
                    : $order?->plate_number;
                $currentPhone = $order?->customer_id === null
                    ? null
                    : Customer::query()->whereKey((int) $order->customer_id)->value('phone');
                $identityDiffers = $order === null
                    || trim((string) $currentPhone) !== $phone
                    || $order->plate_number !== $plate;

                if ($phonePresent && $identityDiffers && ! $this->phoneGuard->allows(
                    (string) $session->uuid,
                    (int) $session->branch_id,
                    $ip,
                    $phone,
                )) {
                    throw new QrDineInException(
                        'qr_identity_limit_exceeded',
                        429,
                        'Too many customer identities were submitted.',
                    );
                }
                $customer = $phonePresent ? $this->customers->handle(
                    (int) $session->company_id,
                    $phone,
                    $plate,
                ) : null;
            }

            if ($order !== null && $customer !== null && $order->customer_id !== $customer->customerId && TableLoyaltyDiscount::amount($order) > 0) {
                TableLoyaltyDiscount::clear($order);
                $this->refreshTotals->handle($order);
            }
            if ($order !== null && $order->qr_session_id === null) {
                $order->update([
                    'qr_session_id' => $session->id,
                    'source' => Order::SOURCE_QR_WEB,
                    'device_id' => $session->device_id ?? $order->device_id,
                    'customer_id' => $customer?->customerId ?? $order->customer_id,
                    'plate_number' => $plate,
                ]);
                $this->journal->handle($seating, 'attached', [
                    'session_uuid' => (string) $session->uuid,
                    'adopted_order_uuid' => (string) $order->uuid,
                ], null, $now);
            }

            $staffConfirm = $session->origin === 'table_card'
                || $this->roundMode->forBranch((int) $session->company_id, (int) $session->branch_id)
                === DineInRoundMode::STAFF_CONFIRM;
            $confirmPayload = $staffConfirm
                ? $this->append->buildPayload($session, $loaded, $price, $now)
                : null;

            $round = QrOrderRound::query()->create([
                'qr_session_id' => $session->id,
                'table_session_id' => $seating->id,
                'order_id' => $order?->id,
                'round_no' => $roundNo,
                'status' => $staffConfirm
                    ? QrOrderRound::STATUS_PENDING_CONFIRMATION
                    : QrOrderRound::STATUS_ACCEPTED,
                'client_request_id' => $clientRequestId,
                'priced_lines' => $this->freeze->handle($loaded, $price),
                'confirm_payload' => $confirmPayload,
                'accepted_seq' => null,
                'subtotal_baisas' => $price->rawSubtotalBaisas,
                'tax_baisas' => $price->taxTotalBaisas,
                'total_baisas' => $price->grandTotalBaisas,
                'submitted_at' => $now,
                'resolved_at' => $staffConfirm ? null : $now,
                'resolved_by_device_id' => null,
            ]);

            if ($order === null) {
                $reference = $seating->temp_reference;
                if (trim((string) $reference) === '') {
                    $reference = $this->tempReferences->handle(
                        (int) $session->company_id,
                        (int) $session->branch_id,
                    );
                    TableSession::query()
                        ->whereKey($seating->id)
                        ->where('company_id', (int) $session->company_id)
                        ->where('branch_id', (int) $session->branch_id)
                        ->where('table_id', (int) $session->table_id)
                        ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
                        ->update(['temp_reference' => $reference]);
                }
                $order = Order::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $session->company_id,
                    'branch_id' => $session->branch_id,
                    'device_id' => $device?->id,
                    'qr_session_id' => $session->id,
                    'table_session_id' => $seating->id,
                    'client_request_id' => $clientRequestId,
                    'staff_id' => null,
                    'customer_id' => $customer?->customerId,
                    'table_id' => $session->table_id,
                    'order_type' => 'dine_in',
                    'status' => Order::STATUS_OPEN,
                    'source' => Order::SOURCE_QR_WEB,
                    'plate_number' => $plate,
                    'subtotal' => Money::toOmr(0),
                    'discount_total' => Money::toOmr(0),
                    'comp_total' => Money::toOmr(0),
                    'tax_total' => Money::toOmr(0),
                    'grand_total' => Money::toOmr(0),
                    'opened_at' => $now,
                    'closed_at' => null,
                    'client_event_id' => null,
                    'receipt_number' => null,
                    'temp_reference' => $reference,
                ]);
                $round->update(['order_id' => $order->id]);
            } elseif ($identityDiffers) {
                $order->update([
                    'customer_id' => $customer?->customerId ?? $order->customer_id,
                    'plate_number' => $plate,
                ]);
            }

            if ($seating->order_id === null) {
                TableSession::query()
                    ->whereKey($seating->id)
                    ->where('company_id', (int) $session->company_id)
                    ->where('branch_id', (int) $session->branch_id)
                    ->where('table_id', (int) $session->table_id)
                    ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
                    ->whereNull('order_id')
                    ->update(['order_id' => $order->id]);
            }

            if (! $staffConfirm) {
                $itemIds = $this->append->handle($order, $session, $loaded, $price, $now);
                $lines = $round->priced_lines;
                foreach ($lines as $index => &$line) {
                    $line['order_item_id'] = $itemIds[$index];
                }
                unset($line);
                $round->update(['priced_lines' => $lines]);
                $this->refreshTotals->handle($order);
            }
            foreach ($joinedSeatings as $joined) {
                if ($joined->order_id === null) {
                    $joined->update(['order_id' => $order->id]);
                    DB::table('pos_order_tables')->insertOrIgnore([
                        'order_id' => $order->id, 'table_id' => $joined->table_id,
                    ]);
                }
            }
            $session->update(['last_seen_at' => $now]);

            if (! $staffConfirm) {
                // Allocate as late as possible: PostgreSQL holds the fixed
                // advisory xact lock from here through commit, making sequence
                // order match visibility order for feed cursors.
                $round->update([
                    'accepted_seq' => $this->acceptedSequence->next(),
                ]);
            }

            $eventPayload = ['round_id' => (int) $round->id, 'order_uuid' => (string) $order->uuid];
            $identityPayload = $eventPayload + [
                'optional_identity' => true,
                'customer_identity_set' => $order->customer_id !== null,
            ];
            $this->journal->handle($seating, $staffConfirm ? 'round_pending' : 'round_appended',
                $staffConfirm ? $identityPayload + [
                    'credential_origin' => $session->origin,
                    'geofence' => $session->scan_geofence_verdict,
                ] : $identityPayload, null, $now);
            $this->journal->handle($seating, 'customer_order_arrived', $eventPayload, null, $now);

            return [
                'round' => $round->fresh(),
                'order' => $order->fresh(),
                'replayed' => false,
            ];
        }, 5);
    }

    private function isUsableOpeningStation(QrSession $session, ?Device $device): bool
    {
        return $device !== null
            && ! $device->trashed()
            && $device->status === 'active'
            && $device->isAssigned()
            && $device->isPaymentStation()
            && (int) $device->company_id === (int) $session->company_id
            && (int) $device->branch_id === (int) $session->branch_id;
    }
}
