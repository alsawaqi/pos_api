<?php

declare(strict_types=1);

namespace App\Actions\Qr;

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

/** Prices one dine-in round once, freezes it, and appends only its new rows. */
final class SubmitDineInQrRoundAction
{
    public function __construct(
        private readonly LoadQrPricingInputAction $pricing,
        private readonly ResolveQrCustomerAction $customers,
        private readonly DistinctQrPhoneGuard $phoneGuard,
        private readonly AllocateQrTempReferenceAction $tempReferences,
        private readonly FreezeQrRoundLinesAction $freeze,
        private readonly AppendQrPricedLinesAction $append,
        private readonly DineInRoundMode $roundMode,
        private readonly RefreshQrOrderTotalsAction $refreshTotals,
        private readonly AllocateQrRoundAcceptedSequenceAction $acceptedSequence,
        private readonly EnsureTableSessionForQrSessionAction $seatings,
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
        // When a tab already exists, every writer locks order -> session.
        // First-round creation has no order yet and is safely serialized by
        // the session lock alone.
        $knownOrderId = Order::query()
            ->where('qr_session_id', $sessionId)
            ->latest('id')
            ->value('id');

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
                    ->where('qr_session_id', $sessionId)
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
            $order ??= Order::query()
                ->where('qr_session_id', $session->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

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
            if (! $this->isUsableOpeningStation($session, $device)) {
                throw new QrDineInException(
                    'qr_session_not_found',
                    404,
                    'QR session was not found.',
                );
            }

            $seating = $this->seatings->handle($session, $order, $device);

            $roundNo = ((int) QrOrderRound::query()
                ->where('qr_session_id', $session->id)
                ->max('round_no')) + 1;
            $acceptedRoundExists = QrOrderRound::query()
                ->where('qr_session_id', $session->id)
                ->where('status', QrOrderRound::STATUS_ACCEPTED)
                ->exists();
            $identityAllowed = ! $acceptedRoundExists;
            $phonePresent = array_key_exists('phone', $payload)
                && is_string($payload['phone'])
                && $payload['phone'] !== '';
            if ($identityAllowed && ! $phonePresent) {
                throw new QrDineInException(
                    'qr_round_phone_required',
                    422,
                    'A phone number is required until the first round is accepted.',
                );
            }
            if (! $identityAllowed
                && (array_key_exists('phone', $payload) || array_key_exists('plate_number', $payload))) {
                throw new QrDineInException(
                    'qr_round_identity_already_set',
                    422,
                    'Customer identity is already fixed by an accepted round.',
                );
            }

            if ($order !== null && $order->status !== Order::STATUS_OPEN) {
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

            $customer = null;
            $identityDiffers = false;
            if ($identityAllowed) {
                $phone = trim((string) $payload['phone']);
                $plate = isset($payload['plate_number'])
                    ? ResolveQrCustomerAction::normalisePlate((string) $payload['plate_number'])
                    : null;
                $currentPhone = $order?->customer_id === null
                    ? null
                    : Customer::query()->whereKey((int) $order->customer_id)->value('phone');
                $identityDiffers = $order === null
                    || trim((string) $currentPhone) !== $phone
                    || $order->plate_number !== $plate;

                if ($identityDiffers && ! $this->phoneGuard->allows(
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
                $customer = $this->customers->handle(
                    (int) $session->company_id,
                    $phone,
                    $plate,
                );
            }

            $staffConfirm = $this->roundMode->forBranch((int) $session->company_id, (int) $session->branch_id)
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
                    'device_id' => $device->id,
                    'qr_session_id' => $session->id,
                    'table_session_id' => $seating->id,
                    'client_request_id' => $clientRequestId,
                    'staff_id' => null,
                    'customer_id' => $customer?->customerId,
                    'table_id' => $session->table_id,
                    'order_type' => 'dine_in',
                    'status' => Order::STATUS_OPEN,
                    'source' => Order::SOURCE_QR_WEB,
                    'plate_number' => $customer?->plateNumber,
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
            } elseif ($identityDiffers && $customer !== null) {
                $order->update([
                    'customer_id' => $customer->customerId,
                    'plate_number' => $customer->plateNumber,
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
                $this->append->handle($order, $session, $loaded, $price, $now);
                $this->refreshTotals->handle($order);
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
