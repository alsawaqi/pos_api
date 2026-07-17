<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\ForwardCharityDonationAction;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RoundupDonation;
use App\Models\SyncEvent;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 8 — processes a `donation.record` sync event (the card-payment
 * round-up) into a `pos_roundup_donations` row.
 *
 * Ported logic-for-logic from the charity app's CharityTransactionsController
 * store_dhofar (the user's donation function): derive status from the bank
 * receipt, snapshot the device's commission/bank + the branch's geo, record
 * the donation amount. The deliberate difference (the user's design decision):
 * we write our OWN pos-owned table, NOT charity's `charity_transactions`
 * (whose device_id + country_id are NOT NULL and assume a charity device) —
 * charity reporting UNIONs this table later. The pos_payment that generated
 * the round-up gets `roundup_amount` + `charity_transaction_id` linked back.
 *
 * After the POS row commits, the round-up is ALSO forwarded (best-effort) to
 * the charity app (POST /api/donations-pos-roundup) so a real charity_transaction
 * (+ shares) is created linked to this POS device + branch — see
 * {@see ForwardCharityDonationAction}.
 */
class DonationRecordHandler implements SyncEventHandler
{
    public function __construct(
        private readonly ForwardCharityDonationAction $charityForwarder,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;

        $validator = Validator::make($payload, [
            'order_uuid' => ['required', 'string'],
            'amount_baisas' => ['required', 'integer', 'min:1'],
            'receipt' => ['sometimes', 'nullable', 'array'],
            'payment_uuid' => ['sometimes', 'nullable', 'string'],
            // The tender's position in the order.pay payments array — the
            // device cannot know server-minted payment uuids, but rows are
            // inserted in array order, so the index addresses the exact leg.
            // Lets each card leg of a split carry ITS OWN round-up donation.
            'payment_index' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'occurred_at' => ['sometimes', 'nullable', 'date'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('invalid donation.record payload: '.implode('; ', $validator->errors()->all()));
        }

        // Resolve the order scoped to the device's tenant + branch.
        $order = Order::query()
            ->where('uuid', $payload['order_uuid'])
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->first();
        if ($order === null) {
            throw new RuntimeException('order not found for donation.record: '.$payload['order_uuid']);
        }

        // The round-up rides a CARD payment (blueprint §9.6.1) — attach to it.
        $payment = $this->resolveCardPayment(
            (int) $order->id,
            $payload['payment_uuid'] ?? null,
            array_key_exists('payment_index', $payload) && $payload['payment_index'] !== null
                ? (int) $payload['payment_index']
                : null,
        );
        if ($payment === null) {
            throw new RuntimeException('no card payment to attach the round-up to for order: '.$payload['order_uuid']);
        }
        if ($payment->method !== Payment::METHOD_CARD) {
            // A round-up can only ride card money — an index pointing at a
            // cash/bank-POS leg is a device bug, not a choice. Fail loud so
            // the charity amount is never mis-attributed.
            throw new RuntimeException('round-up payment_index does not address a card tender for order: '.$payload['order_uuid']);
        }

        // P-F7 — when the round-up rides a force-recorded (ambiguous) card
        // charge, the money is not confirmed yet: the linked payment — or any
        // other tender on the order — sits pending_reconciliation. The
        // pos_roundup_donations row is still created below, but the charity
        // forwarding is DEFERRED (forwarded_at stays NULL) until the platform
        // admin approves the order against the bank file (pos_admin
        // ApprovePendingReconciliationAction — the twin that forwards it).
        $orderHasPendingTender = (bool) $payment->pending_reconciliation
            || Payment::query()
                ->where('order_id', $order->id)
                ->where('pending_reconciliation', true)
                ->exists();

        $branch = Branch::query()->find($device->branch_id);

        // The device does NOT resend the bank receipt on donation.record — the
        // authoritative Soft POS receipt lives on the CARD payment this round-up
        // rides. Use it so the pos_roundup_donations row (and the forwarded
        // charity_transaction) store the real bank response, falling back to any
        // receipt the payload did carry.
        $receipt = is_array($payment->bank_response)
            ? $payment->bank_response
            : (is_array($payload['receipt'] ?? null) ? $payload['receipt'] : null);

        // A round-up is only forwarded once the money is confirmed. A settled
        // ride ⇒ 'success'; a still-pending ride ⇒ 'pending' until the platform
        // admin approves it (pos_admin ReconcileDeferredEffectsAction flips it to
        // 'success' when it forwards). This replaces the old receipt-derived
        // status that always fell through to 'pending' (the device sends no
        // receipt) and mis-filed every forwarded round-up as 'fail' at charity.
        $status = $orderHasPendingTender ? 'pending' : 'success';
        $amount = Money::toOmr((int) $payload['amount_baisas']);

        $result = DB::transaction(function () use ($payload, $event, $device, $order, $payment, $branch, $receipt, $status, $amount): array {
            $donation = RoundupDonation::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'device_id' => $device->getKey(),
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'bank_id' => $device->bank_id,
                'terminal_id' => $device->terminal_id,
                'commission_profile_id' => $device->commission_profile_id,
                'amount' => $amount,
                'bank_response' => $receipt,
                'status' => $status,
                'source' => 'pos_roundup',
                'country_id' => $branch?->country_id,
                'region_id' => $branch?->region_id,
                'district_id' => $branch?->district_id,
                'city_id' => $branch?->city_id,
                'latitude' => $branch?->latitude,
                'longitude' => $branch?->longitude,
                'client_event_id' => $event->client_event_id,
                'occurred_at' => isset($payload['occurred_at'])
                    ? Carbon::parse((string) $payload['occurred_at'])
                    : $event->client_timestamp,
            ]);

            // Breadcrumb on the card payment: how much went to charity + the link.
            $payment->forceFill([
                'roundup_amount' => $amount,
                'charity_transaction_id' => $donation->id,
            ])->save();

            return [
                'roundup_donation_id' => (int) $donation->id,
                'payment_id' => (int) $payment->id,
                'status' => $donation->status,
            ];
        });

        // After the POS round-up has durably committed, forward it to the
        // charity app (best-effort — never fails the round-up) so a real
        // charity_transaction + shares are created, linked to this POS device +
        // branch with the branch's geo copied across. A successful forward
        // stamps forwarded_at so the admin reconciliation paths never forward
        // the same round-up twice.
        //
        // P-F7 — SKIPPED when the order has a pending_reconciliation tender:
        // money not confirmed ⇒ nothing goes to charity yet. forwarded_at
        // stays NULL and pos_admin forwards it on reconciliation approval.
        if (! $orderHasPendingTender) {
            $forwarded = $this->charityForwarder->forward($device, $branch, $amount, $receipt, $status);
            if ($forwarded) {
                RoundupDonation::query()
                    ->whereKey($result['roundup_donation_id'])
                    ->update(['forwarded_at' => now()]);
            }
        }

        return $result;
    }

    private function resolveCardPayment(int $orderId, ?string $paymentUuid, ?int $paymentIndex): ?Payment
    {
        if ($paymentUuid !== null && $paymentUuid !== '') {
            return Payment::query()
                ->where('uuid', $paymentUuid)
                ->where('order_id', $orderId)
                ->first();
        }

        // Positional address: PayOrderHandler inserts one row per tender in
        // array order (ascending ids), so payments-ordered-by-id[index] is the
        // exact leg the device rounded. Out-of-range ⇒ null (caller throws).
        if ($paymentIndex !== null) {
            return Payment::query()
                ->where('order_id', $orderId)
                ->orderBy('id')
                ->skip($paymentIndex)
                ->first();
        }

        // Legacy devices (no index): the latest card payment.
        return Payment::query()
            ->where('order_id', $orderId)
            ->where('method', Payment::METHOD_CARD)
            ->latest('id')
            ->first();
    }
}
