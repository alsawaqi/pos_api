<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Device\AllocateOrderNumberAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
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
        private readonly AllocateOrderNumberAction $numbers,
        private readonly FreezeQrRoundLinesAction $freeze,
        private readonly AppendQrPricedLinesAction $append,
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
        return DB::transaction(function () use ($sessionId, $payload, $ip): array {
            $session = QrSession::query()->whereKey($sessionId)->lockForUpdate()->first();
            if ($session === null || ! $session->isDineIn()) {
                throw new QrDineInException(
                    'qr_dine_in_session_required',
                    409,
                    'A live dine-in QR session is required.',
                );
            }

            $clientRequestId = (string) $payload['client_request_id'];
            $existing = QrOrderRound::query()
                ->where('qr_session_id', $session->id)
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existing !== null) {
                $order = $existing->order_id === null
                    ? null
                    : Order::query()->find((int) $existing->order_id);
                if (! $order instanceof Order) {
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

            $roundNo = ((int) QrOrderRound::query()
                ->where('qr_session_id', $session->id)
                ->max('round_no')) + 1;
            $firstRound = $roundNo === 1;
            $phonePresent = array_key_exists('phone', $payload)
                && is_string($payload['phone'])
                && $payload['phone'] !== '';
            if ($firstRound && ! $phonePresent) {
                throw new QrDineInException(
                    'qr_round_phone_required',
                    422,
                    'A phone number is required for the first round.',
                );
            }
            if (! $firstRound
                && (array_key_exists('phone', $payload) || array_key_exists('plate_number', $payload))) {
                throw new QrDineInException(
                    'qr_round_identity_already_set',
                    422,
                    'Customer identity is accepted on the first round only.',
                );
            }

            $order = Order::query()
                ->where('qr_session_id', $session->id)
                ->latest('id')
                ->first();
            if ($order !== null && $order->status !== Order::STATUS_OPEN) {
                throw new QrDineInException(
                    'qr_round_order_not_open',
                    409,
                    'This dine-in order is not open.',
                );
            }
            if (! $firstRound && $order === null) {
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
            if ($firstRound) {
                $phone = (string) $payload['phone'];
                if (! $this->phoneGuard->allows(
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
                    isset($payload['plate_number']) ? (string) $payload['plate_number'] : null,
                );
            }

            $round = QrOrderRound::query()->create([
                'qr_session_id' => $session->id,
                'order_id' => $order?->id,
                'round_no' => $roundNo,
                'status' => QrOrderRound::STATUS_ACCEPTED,
                'client_request_id' => $clientRequestId,
                'priced_lines' => $this->freeze->handle($loaded, $price),
                'subtotal_baisas' => $price->rawSubtotalBaisas,
                'tax_baisas' => $price->taxTotalBaisas,
                'total_baisas' => $price->grandTotalBaisas,
                'submitted_at' => $now,
                'resolved_at' => $now,
                'resolved_by_device_id' => null,
            ]);

            if ($order === null) {
                $allocation = $this->numbers->handle($device);
                $order = Order::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $session->company_id,
                    'branch_id' => $session->branch_id,
                    'device_id' => $device->id,
                    'qr_session_id' => $session->id,
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
                    'receipt_number' => $allocation['formatted'] ?? null,
                ]);
                $round->update(['order_id' => $order->id]);
            }

            $this->append->handle($order, $session, $loaded, $price, $now);
            $this->refreshOrderTotals($order);
            $session->update(['last_seen_at' => $now]);

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

    private function refreshOrderTotals(Order $order): void
    {
        $totals = QrOrderRound::query()
            ->where('order_id', $order->id)
            ->where('status', QrOrderRound::STATUS_ACCEPTED)
            ->selectRaw(
                'COALESCE(SUM(subtotal_baisas), 0) AS subtotal_baisas, '.
                'COALESCE(SUM(tax_baisas), 0) AS tax_baisas, '.
                'COALESCE(SUM(total_baisas), 0) AS total_baisas',
            )
            ->first();

        $subtotal = (int) $totals->subtotal_baisas;
        $tax = (int) $totals->tax_baisas;
        $total = (int) $totals->total_baisas;
        $discount = max(0, $subtotal + $tax - $total);

        $order->update([
            'subtotal' => Money::toOmr($subtotal),
            'discount_total' => Money::toOmr($discount),
            'tax_total' => Money::toOmr($tax),
            'grand_total' => Money::toOmr($total),
        ]);
    }
}
