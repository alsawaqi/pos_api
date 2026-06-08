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
 * the charity app's store_dhofar so a real charity_transaction (+ shares) is
 * created for a dual-registered device — see {@see ForwardCharityDonationAction}.
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
        $payment = $this->resolveCardPayment((int) $order->id, $payload['payment_uuid'] ?? null);
        if ($payment === null) {
            throw new RuntimeException('no card payment to attach the round-up to for order: '.$payload['order_uuid']);
        }

        $branch = Branch::query()->find($device->branch_id);

        $receipt = is_array($payload['receipt'] ?? null) ? $payload['receipt'] : null;
        $status = $this->statusFromReceipt($receipt);
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
        // charity store_dhofar (best-effort — never fails the round-up) so a
        // real charity_transaction + shares are created for a dual-registered
        // device. A POS-only device (no charity twin) is silently skipped.
        $this->charityForwarder->forward(
            $device,
            $amount,
            $receipt,
            $branch?->latitude !== null ? (float) $branch->latitude : null,
            $branch?->longitude !== null ? (float) $branch->longitude : null,
        );

        return $result;
    }

    private function resolveCardPayment(int $orderId, ?string $paymentUuid): ?Payment
    {
        if ($paymentUuid !== null && $paymentUuid !== '') {
            return Payment::query()
                ->where('uuid', $paymentUuid)
                ->where('order_id', $orderId)
                ->first();
        }

        return Payment::query()
            ->where('order_id', $orderId)
            ->where('method', Payment::METHOD_CARD)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $receipt
     */
    private function statusFromReceipt(?array $receipt): string
    {
        if ($receipt === null || ! isset($receipt['status'])) {
            return 'pending';
        }

        return strtolower(trim((string) $receipt['status'])) === 'success' ? 'success' : 'fail';
    }
}
