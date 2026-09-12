<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Actions\Tables\StaffTableCheckoutAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;

/** Fail-closed attended reversal of a dine-in payment request. */
final class ReopenDineInQrPaymentAction
{
    /** @var list<string> */
    private const CHARGE_FIELDS = [
        'charge_device_id',
        'charge_amount_baisas',
        'charge_roundup_amount_baisas',
        'charge_claimed_at',
        'charge_deadline_at',
        'charge_outcome',
    ];

    public function __construct(
        private readonly QrChargeRecoveryGuard $recovery,
        private readonly EnsureTableSessionForQrSessionAction $seatings,
        private readonly AppendTableSessionEventAction $journal,
        private readonly StaffTableCheckoutAction $staffCheckout,
    ) {}

    /** @return array{order_uuid: string, status: string, session_status: string} */
    public function handle(Device $device, string $orderUuid): array
    {
        if (($staffOrder = $this->staffCheckout->find($device, $orderUuid)) !== null) {
            try {
                return $this->staffCheckout->reopen($device, $staffOrder);
            } catch (QrChargeException $exception) {
                throw new QrDineInException($exception->codeName, $exception->httpStatus, $exception->getMessage());
            }
        }
        if (! $this->recovery->isAttendedDevice($device)) {
            throw new QrDineInException(
                'device_not_attended',
                409,
                'Only an attended fixed POS or handheld device may reopen payment.',
            );
        }

        return DB::transaction(function () use ($device, $orderUuid): array {
            $order = Order::query()
                ->where('uuid', trim($orderUuid))
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->where('source', Order::SOURCE_QR_WEB)
                ->where('order_type', 'dine_in')
                ->whereNotNull('table_id')
                ->lockForUpdate()
                ->first();
            if ($order === null || $order->qr_session_id === null) {
                throw new QrDineInException('order_not_found', 404, 'The order was not found.');
            }

            $session = QrSession::query()
                ->whereKey((int) $order->qr_session_id)
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->whereNotNull('table_id')
                ->lockForUpdate()
                ->first();
            if ($session === null) {
                throw new QrDineInException('order_not_found', 404, 'The order was not found.');
            }
            if ($session->expires_at === null || $session->expires_at->lte(now())) {
                throw new QrDineInException(
                    'qr_session_expired',
                    409,
                    'This table session has expired and requires attended settlement.',
                );
            }
            if ($session->status !== QrSession::STATUS_ORDERED) {
                throw new QrDineInException(
                    'qr_session_not_ordered',
                    409,
                    'This table session is not awaiting payment.',
                );
            }

            if ($order->status === Order::STATUS_HELD) {
                // Attended ambiguous fallback also produces HELD while retaining
                // possibly-charged evidence. Only the residue-free counter path
                // is safe to reopen.
                if (! $this->hasNoChargeProvenance($order)) {
                    throw new QrDineInException(
                        'qr_charge_recovery_required',
                        409,
                        'This payment has ambiguous charge evidence and cannot be reopened.',
                    );
                }
            } elseif ($order->status === Order::STATUS_AWAITING_PAYMENT) {
                $safe = Order::query()
                    ->whereKey($order->id)
                    ->withoutLiveClaim(now())
                    ->exists();
                if (! $safe) {
                    throw new QrDineInException(
                        'qr_charge_recovery_required',
                        409,
                        'This payment has ambiguous charge evidence and cannot be reopened.',
                    );
                }
            } else {
                throw new QrDineInException(
                    'qr_order_not_reopenable',
                    409,
                    'This dine-in order cannot be reopened.',
                );
            }

            $updates = ['status' => Order::STATUS_OPEN];
            foreach (self::CHARGE_FIELDS as $field) {
                $updates[$field] = null;
            }
            $order->update($updates);
            $session->update([
                'status' => QrSession::STATUS_ACTIVE,
                'closed_at' => null,
                'last_seen_at' => now(),
            ]);

            $seating = $this->seatings->handle($session, $order, $device);
            TableSession::query()
                ->whereKey($seating->id)
                ->where('company_id', (int) $order->company_id)
                ->where('branch_id', (int) $order->branch_id)
                ->where('table_id', (int) $order->table_id)
                ->where('status', TableSession::STATUS_BILLING)
                ->update(['status' => TableSession::STATUS_OPEN, 'billing_at' => null]);
            $this->journal->handle($seating, 'reopened', ['order_uuid' => (string) $order->uuid], (int) $device->id);

            return [
                'order_uuid' => (string) $order->uuid,
                'status' => Order::STATUS_OPEN,
                'session_status' => QrSession::STATUS_ACTIVE,
            ];
        });
    }

    private function hasNoChargeProvenance(Order $order): bool
    {
        foreach (self::CHARGE_FIELDS as $field) {
            if ($order->getRawOriginal($field) !== null) {
                return false;
            }
        }

        return true;
    }
}
