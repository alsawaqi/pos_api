<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Open the one live dine-in QR session allowed for a branch table. */
final class OpenDineInTableAction
{
    public function __construct(
        private readonly AllocateQrTempReferenceAction $tempReferences,
        private readonly SupersedeAbandonedTableSessionAction $supersede,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /**
     * @return array{session: QrSession, table_token: string}
     */
    public function handle(Device $authenticatedDevice, int $tableId): array
    {
        return DB::transaction(function () use ($authenticatedDevice, $tableId): array {
            $device = Device::query()
                ->withTrashed()
                ->whereKey($authenticatedDevice->getKey())
                ->lockForUpdate()
                ->first();

            $this->assertOpeningStation($device);

            // Owner decision: open-table has no server PIN. The station client
            // protects the picker with a staff gesture; this is accepted risk.
            //
            // Operational warning: deactivating, unassigning, or deleting this
            // station while tables are open kills every seated customer's
            // session on their next request (hard-delete cascades the rows).
            $table = Table::query()
                ->whereKey($tableId)
                ->where('company_id', (int) $device->company_id)
                ->lockForUpdate()
                ->first();

            if ($table === null) {
                throw new QrDineInException(
                    'qr_table_not_found',
                    404,
                    'The table was not found.',
                );
            }

            $floorExists = Floor::query()
                ->whereKey((int) $table->floor_id)
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->where('status', 'active')
                ->exists();

            if (! $floorExists) {
                throw new QrDineInException(
                    'qr_table_not_found',
                    404,
                    'The table was not found.',
                );
            }

            if ((string) $table->status !== 'active') {
                throw new QrDineInException(
                    'qr_table_not_active',
                    409,
                    'The table is not active.',
                );
            }

            $tableToken = is_string($table->qr_token) ? trim($table->qr_token) : '';
            if ($tableToken === '') {
                throw new QrDineInException(
                    'qr_table_token_missing',
                    409,
                    'The table does not have a QR token.',
                );
            }

            $now = now();
            QrSession::query()
                ->where('table_id', $table->getKey())
                ->whereIn('status', QrSession::EXPIRABLE_STATUSES)
                ->where('expires_at', '<=', $now)
                ->update([
                    'status' => QrSession::STATUS_EXPIRED,
                    'closed_at' => $now,
                    'updated_at' => $now,
                ]);

            // Owner-approved ordering exception: joined members must be
            // refused before primary-only supersede can expire or inspect them.
            $liveSeating = TableSession::query()
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->where('table_id', $table->getKey())
                ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
                ->lockForUpdate()
                ->first();
            if ($liveSeating?->merged_into_id !== null) {
                throw new QrDineInException(
                    'qr_table_joined',
                    409,
                    'This table belongs to another table\'s seating.',
                );
            }

            $this->supersede->handle($table, $device, $now);

            $hasLiveSession = QrSession::query()
                ->where('table_id', $table->getKey())
                ->whereIn('status', [
                    QrSession::STATUS_PENDING,
                    QrSession::STATUS_ACTIVE,
                    QrSession::STATUS_ORDERED,
                ])
                ->exists();

            if ($hasLiveSession) {
                throw new QrDineInException(
                    'qr_table_already_open',
                    409,
                    'The table already has an open QR session.',
                );
            }

            $seating = TableSession::query()
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->where('table_id', $table->getKey())
                ->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING])
                ->lockForUpdate()
                ->first();

            // Only the seating's own open staff bill may gain its first QR
            // credential. QR lineages, frozen bills and other unpaid bills refuse.
            $hasUnpaidOrder = Order::query()
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->whereIn('status', [
                    Order::STATUS_OPEN,
                    Order::STATUS_HELD,
                    Order::STATUS_AWAITING_PAYMENT,
                ])
                ->where(static function (Builder $orders) use ($table): void {
                    $orders
                        ->where('table_id', $table->getKey())
                        ->orWhereIn(
                            'id',
                            DB::table('pos_order_tables')
                                ->select('order_id')
                                ->where('table_id', $table->getKey()),
                        );
                })
                ->when($seating?->order_id !== null, static function (Builder $orders) use ($seating): void {
                    $orders->where(static function (Builder $otherUnpaid) use ($seating): void {
                        $otherUnpaid->where('id', '!=', (int) $seating->order_id)
                            ->orWhere('status', '!=', Order::STATUS_OPEN)
                            ->orWhereNotNull('qr_session_id')
                            ->orWhereNotIn('source', ['main_pos', 'handheld'])
                            ->orWhereNull('source');
                    });
                })
                ->exists();

            if ($hasUnpaidOrder) {
                throw new QrDineInException(
                    'qr_table_has_unpaid_order',
                    409,
                    'The table still has an unpaid order.',
                );
            }

            $lifetimeHours = max(1, (int) config('qr.dine_in_session_lifetime_hours', 6));
            $horizon = $now->copy()->addHours($lifetimeHours);

            $session = QrSession::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'device_id' => $device->getKey(),
                'table_id' => $table->getKey(),
                'table_session_id' => $seating?->id,
                'token' => bin2hex(random_bytes(32)),
                'token_expires_at' => $horizon,
                'status' => QrSession::STATUS_PENDING,
                'expires_at' => $horizon,
            ]);

            if ($seating !== null) {
                $this->journal->handle($seating, 'attached', [
                    'session_uuid' => (string) $session->uuid, 'station_attach' => true,
                ], (int) $device->id, $now);

                return [
                    'session' => $session,
                    'table_token' => $tableToken,
                ];
            }

            $seating = TableSession::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'table_id' => $table->getKey(),
                'status' => TableSession::STATUS_OPEN,
                'origin' => TableSession::ORIGIN_STATION,
                'opened_by_device_id' => $device->getKey(),
                'opened_at' => $now,
                'expires_at' => $horizon,
            ]);
            // The new seating exists before taking the shared temporary counter.
            TableSession::query()
                ->whereKey($seating->id)
                ->where('company_id', (int) $device->company_id)
                ->where('branch_id', (int) $device->branch_id)
                ->where('table_id', $table->getKey())
                ->where('status', TableSession::STATUS_OPEN)
                ->update(['temp_reference' => $this->tempReferences->handle(
                    (int) $device->company_id,
                    (int) $device->branch_id,
                )]);
            $session->update(['table_session_id' => $seating->id]);
            $this->journal->handle($seating, 'opened', ['session_uuid' => (string) $session->uuid], (int) $device->id, $now);

            return [
                'session' => $session,
                'table_token' => $tableToken,
            ];
        }, 5);
    }

    private function assertOpeningStation(?Device $device): Device
    {
        if ($device === null || $device->trashed() || $device->status !== 'active') {
            throw new QrDineInException(
                'device_not_active',
                409,
                'This payment station is not active.',
            );
        }

        if (! $device->isAssigned()) {
            throw new QrDineInException(
                'device_unassigned',
                409,
                'This device is not assigned to a branch.',
            );
        }

        if (! $device->isPaymentStation()) {
            throw new QrDineInException(
                'device_not_payment_station',
                409,
                'Only a payment station may open a QR table.',
            );
        }

        return $device;
    }
}
