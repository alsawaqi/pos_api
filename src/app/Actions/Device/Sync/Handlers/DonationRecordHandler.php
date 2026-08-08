<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\AfterSyncEventCommitHandler;
use App\Actions\Device\Sync\ForwardCharityDonationAction;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RoundupDonation;
use App\Models\SyncEvent;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
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
class DonationRecordHandler implements AfterSyncEventCommitHandler
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

        $branch = Branch::query()->find($device->branch_id);
        $amount = Money::toOmr((int) $payload['amount_baisas']);
        $paymentUuid = isset($payload['payment_uuid']) ? (string) $payload['payment_uuid'] : null;
        $paymentIndex = array_key_exists('payment_index', $payload) && $payload['payment_index'] !== null
            ? (int) $payload['payment_index']
            : null;

        return DB::transaction(function () use ($payload, $event, $device, $branch, $amount, $paymentUuid, $paymentIndex): array {
            // order.void takes the order lock before touching donations or
            // payment breadcrumbs. Use the same root lock, then freeze every
            // tender in id order so a concurrent pending-reconciliation
            // approval linearizes either wholly before or wholly after this
            // donation snapshot.
            $order = Order::query()
                ->where('uuid', $payload['order_uuid'])
                ->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)
                ->lockForUpdate()
                ->first();
            if ($order === null) {
                throw new RuntimeException('order not found for donation.record: '.$payload['order_uuid']);
            }

            /** @var Collection<int, Payment> $payments */
            $payments = Payment::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // The round-up rides a CARD payment (blueprint §9.6.1) — attach to it.
            $payment = $this->resolveCardPayment($payments, $paymentUuid, $paymentIndex);
            if ($payment === null) {
                throw new RuntimeException('no card payment to attach the round-up to for order: '.$payload['order_uuid']);
            }
            if ($payment->method !== Payment::METHOD_CARD) {
                // A round-up can only ride card money — an index pointing at a
                // cash/bank-POS leg is a device bug, not a choice. Fail loud so
                // the charity amount is never mis-attributed.
                throw new RuntimeException('round-up payment_index does not address a card tender for order: '.$payload['order_uuid']);
            }

            // P-F7 — freeze the WHOLE split tender set while deciding whether
            // money is confirmed. If admin approval wins the payment locks we
            // observe success; if this handler wins, approval waits until the
            // pending donation is present and can forward it after settlement.
            $orderHasPendingTender = $payments->contains(
                fn (Payment $candidate): bool => (bool) $candidate->pending_reconciliation,
            );

            // The authoritative Soft POS receipt lives on the selected card.
            $receipt = is_array($payment->bank_response)
                ? $payment->bank_response
                : (is_array($payload['receipt'] ?? null) ? $payload['receipt'] : null);
            $status = $orderHasPendingTender ? 'pending' : 'success';

            $donation = RoundupDonation::query()
                ->where('client_event_id', $event->client_event_id)
                ->lockForUpdate()
                ->first();

            if ($donation === null) {
                // A stranded donation.record may be replayed after a later
                // order.void. It must never resurrect charity money after that
                // terminal decision. An already-existing void donation remains
                // replayable below so its original ACK can still settle.
                if ($order->status === Order::STATUS_VOID) {
                    throw new RuntimeException('order already void for donation.record: '.$payload['order_uuid']);
                }

                $donation = RoundupDonation::create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $device->company_id,
                    'branch_id' => $device->branch_id,
                    'branch_name' => $branch?->name,
                    'device_id' => $device->getKey(),
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'bank_id' => $device->bank_id,
                    'terminal_id' => $device->terminal_id,
                    'commission_profile_id' => $device->commission_profile_id,
                    'organization_id' => $device->organization_id,
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

                // Breadcrumb on the card payment: amount plus durable link.
                $payment->forceFill([
                    'roundup_amount' => $amount,
                    'charity_transaction_id' => $donation->id,
                ])->save();
            } else {
                $this->assertExistingDonationMatches($donation, $device, $order, $payment, $amount);

                if ($order->status === Order::STATUS_VOID && $donation->status !== 'void') {
                    throw new RuntimeException('order already void for donation.record: '.$payload['order_uuid']);
                }
            }

            return [
                'roundup_donation_id' => (int) $donation->id,
                'roundup_donation_uuid' => (string) $donation->uuid,
                'payment_id' => (int) $payment->id,
                'status' => $donation->status,
            ];
        });
    }

    /**
     * Forward only after the local donation, payment breadcrumb, and processed
     * sync stamp commit together. A failed forward leaves forwarded_at null for
     * the admin retry path; a lost success reuses the same charity-side UUID.
     *
     * Pending-reconciliation donations stay local until approval changes their
     * status and the existing admin recovery path forwards them.
     *
     * @param  array<string, mixed>  $result
     */
    public function afterSyncEventCommit(SyncEvent $event, Device $device, array $result): void
    {
        $donationId = (int) ($result['roundup_donation_id'] ?? 0);
        if ($donationId <= 0) {
            return;
        }

        // Candidate lookup supplies the order id without taking locks out of
        // order. Every mutable eligibility predicate is rechecked below.
        $candidate = RoundupDonation::query()
            ->whereKey($donationId)
            ->where('client_event_id', $event->client_event_id)
            ->where('device_id', $device->getKey())
            ->first(['id', 'order_id']);
        if ($candidate === null) {
            return;
        }

        DB::transaction(function () use ($candidate, $event, $device): void {
            // order.void uses this same order → donation lock order. Keep both
            // locks through the bounded (8s), charity-idempotent HTTP call and
            // forwarded_at stamp: either the forward linearizes first and void
            // follows, or a committed void wins and this path sends nothing.
            $order = Order::query()
                ->whereKey($candidate->order_id)
                ->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)
                ->lockForUpdate()
                ->first();
            if ($order === null || $order->status === Order::STATUS_VOID) {
                return;
            }

            $donation = RoundupDonation::query()
                ->whereKey($candidate->id)
                ->where('order_id', $order->id)
                ->where('client_event_id', $event->client_event_id)
                ->where('device_id', $device->getKey())
                ->whereNull('forwarded_at')
                ->where('status', 'success')
                ->lockForUpdate()
                ->first();
            if ($donation === null) {
                return;
            }

            $forwarded = $this->charityForwarder->forwardSnapshot($donation);

            if ($forwarded) {
                $donation->forceFill(['forwarded_at' => now()])->save();
            }
        });
    }

    private function assertExistingDonationMatches(
        RoundupDonation $donation,
        Device $device,
        Order $order,
        Payment $payment,
        string $amount,
    ): void {
        $matches = (int) $donation->company_id === (int) $device->company_id
            && (int) $donation->branch_id === (int) $device->branch_id
            && (int) $donation->device_id === (int) $device->getKey()
            && (int) $donation->order_id === (int) $order->id
            && (int) $donation->payment_id === (int) $payment->id
            && (string) $donation->amount === $amount;

        $breadcrumbsMatch = $donation->status === 'void'
            ? $payment->roundup_amount === null && $payment->charity_transaction_id === null
            : (string) $payment->roundup_amount === $amount
                && (int) $payment->charity_transaction_id === (int) $donation->id;

        if (! $matches || ! $breadcrumbsMatch) {
            throw new RuntimeException(
                'existing donation.record does not match this device event',
            );
        }
    }

    /**
     * @param  Collection<int, Payment>  $payments  order payments already
     *                                              locked in ascending id order
     */
    private function resolveCardPayment(Collection $payments, ?string $paymentUuid, ?int $paymentIndex): ?Payment
    {
        if ($paymentUuid !== null && $paymentUuid !== '') {
            return $payments->firstWhere('uuid', $paymentUuid);
        }

        // Positional address: PayOrderHandler inserts one row per tender in
        // array order (ascending ids), so payments-ordered-by-id[index] is the
        // exact leg the device rounded. Out-of-range ⇒ null (caller throws).
        if ($paymentIndex !== null) {
            return $payments->get($paymentIndex);
        }

        // Legacy devices (no index): the latest card payment.
        return $payments->where('method', Payment::METHOD_CARD)->last();
    }
}
