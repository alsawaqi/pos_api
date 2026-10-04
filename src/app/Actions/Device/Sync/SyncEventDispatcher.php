<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Actions\Device\IngestSyncEventsAction;
use App\Actions\Device\Sync\Handlers\CloseShiftHandler;
use App\Actions\Device\Sync\Handlers\CreateOrderHandler;
use App\Actions\Device\Sync\Handlers\DeliverOrderHandler;
use App\Actions\Device\Sync\Handlers\DonationRecordHandler;
use App\Actions\Device\Sync\Handlers\ExpenseLogHandler;
use App\Actions\Device\Sync\Handlers\HoldOrderHandler;
use App\Actions\Device\Sync\Handlers\OpenShiftHandler;
use App\Actions\Device\Sync\Handlers\PayOrderHandler;
use App\Actions\Device\Sync\Handlers\ProductWasteHandler;
use App\Actions\Device\Sync\Handlers\RestockRequestHandler;
use App\Actions\Device\Sync\Handlers\SliderDisplayHandler;
use App\Actions\Device\Sync\Handlers\StaffClockHandler;
use App\Actions\Device\Sync\Handlers\StockCountHandler;
use App\Actions\Device\Sync\Handlers\TableSessionAdjustHandler;
use App\Actions\Device\Sync\Handlers\TableSessionCancelBillHandler;
use App\Actions\Device\Sync\Handlers\TableSessionCancelLineHandler;
use App\Actions\Device\Sync\Handlers\TableSessionCloseHandler;
use App\Actions\Device\Sync\Handlers\TableSessionJoinHandler;
use App\Actions\Device\Sync\Handlers\TableSessionMoveHandler;
use App\Actions\Device\Sync\Handlers\TableSessionOpenHandler;
use App\Actions\Device\Sync\Handlers\TableSessionRoundHandler;
use App\Actions\Device\Sync\Handlers\TransferOrderHandler;
use App\Actions\Device\Sync\Handlers\VoidOrderHandler;
use App\Actions\Qr\QrDineInException;
use App\Events\DeviceSyncBroadcast;
use App\Models\Device;
use App\Models\SyncEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * Phase 8.3 — the processing seam 8.2 deferred.
 *
 * After {@see IngestSyncEventsAction} records a NEW
 * event (ack_status=received), it hands the row here. We route by
 * event_type to the registered handler, then commit the handler's database
 * effect and processed result as one transaction. A failure rolls that whole
 * transaction back before the event is durably stamped failed. Registered
 * post-commit work and broadcasting run only after successful settlement;
 * known handlerless types stay received for the recovery contract.
 */
class SyncEventDispatcher
{
    /** LAUNCH-P3 fix order 1 M2 — how far behind the receive time a client stamp may be before it is flagged. */
    public const CLIENT_TIME_BEHIND_FLAG_HOURS = 24;

    public const CLIENT_TIME_BEHIND_FLAG = 'client_time_behind_24h';

    /** The result_json error of a database failure: the only transient handler failure. */
    public const TRANSIENT_ERROR = 'Could not save this update. Retry the same request.';

    public function __construct(
        private readonly CreateOrderHandler $createOrder,
        private readonly HoldOrderHandler $holdOrder,
        private readonly TransferOrderHandler $transferOrder,
        private readonly PayOrderHandler $payOrder,
        private readonly DeliverOrderHandler $deliverOrder,
        private readonly VoidOrderHandler $voidOrder,
        private readonly OpenShiftHandler $openShift,
        private readonly CloseShiftHandler $closeShift,
        private readonly ExpenseLogHandler $expenseLog,
        private readonly RestockRequestHandler $restockRequest,
        private readonly DonationRecordHandler $donationRecord,
        private readonly StockCountHandler $stockCount,
        private readonly ProductWasteHandler $productWaste,
        private readonly SliderDisplayHandler $sliderDisplay,
        private readonly TableSessionOpenHandler $tableOpen,
        private readonly TableSessionRoundHandler $tableRound,
        private readonly TableSessionMoveHandler $tableMove,
        private readonly TableSessionJoinHandler $tableJoin,
        private readonly TableSessionCloseHandler $tableClose,
        private readonly TableSessionCancelLineHandler $tableCancelLine,
        private readonly TableSessionCancelBillHandler $tableCancelBill,
        private readonly TableSessionAdjustHandler $tableAdjust,
        private readonly StaffClockHandler $staffClock,
    ) {}

    /**
     * The canonical handler registry used by both inline ingest and recovery.
     *
     * @return array<string, SyncEventHandler>
     */
    private function handlers(): array
    {
        return [
            'order.create' => $this->createOrder,
            'order.hold' => $this->holdOrder,
            'order.transfer' => $this->transferOrder,
            'order.pay' => $this->payOrder,
            'order.deliver' => $this->deliverOrder,
            'order.void' => $this->voidOrder,
            'shift.open' => $this->openShift,
            'shift.close' => $this->closeShift,
            'expense.log' => $this->expenseLog,
            'restock.request' => $this->restockRequest,
            'donation.record' => $this->donationRecord,
            'stock.count' => $this->stockCount,
            'product.waste' => $this->productWaste,
            'slider.display' => $this->sliderDisplay,
            'table.session.open' => $this->tableOpen,
            'table.session.round' => $this->tableRound,
            'table.session.move' => $this->tableMove,
            'table.session.join' => $this->tableJoin,
            'table.session.close' => $this->tableClose,
            'table.session.cancel_line' => $this->tableCancelLine,
            'table.session.cancel_bill' => $this->tableCancelBill,
            'table.session.adjust' => $this->tableAdjust,
            'staff.clock_in' => $this->staffClock,
            'staff.clock_out' => $this->staffClock,
        ];
    }

    /**
     * @return list<string>
     */
    public function handledEventTypes(): array
    {
        return array_keys($this->handlers());
    }

    public function handles(string $eventType): bool
    {
        return isset($this->handlers()[$eventType]);
    }

    public function dispatch(SyncEvent $event, Device $device, ?int $reviewId = null): void
    {
        $handler = $this->handlers()[$event->event_type] ?? null;

        if ($handler === null) {
            return;
        }

        try {
            $settlement = DB::transaction(function () use ($handler, $event, &$device, $reviewId): ?array {
                // The cache lease used by recovery callers is only an admission
                // optimization: it can expire, and initial ingest does not own
                // one. The ledger row is the durable serialization boundary for
                // every caller. A contender waits here, then observes the status
                // committed by the winner instead of running the handler twice.
                $lockedEvent = SyncEvent::query()
                    ->whereKey($event->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedEvent !== null && $reviewId !== null) {
                    $device = app(ReviewedSyncReplay::class)->device($lockedEvent, $reviewId);
                }
                if ($lockedEvent === null
                    || ! in_array($lockedEvent->ack_status, [
                        SyncEvent::STATUS_RECEIVED,
                        SyncEvent::STATUS_FAILED,
                        ...($reviewId === null ? [] : [SyncEvent::STATUS_NEEDS_REVIEW]),
                    ], true)) {
                    return null;
                }

                // Serialize assignment with settlement, then verify the durable
                // receipt identity. Never reinterpret old work in today's tenant.
                $current = Device::withTrashed()->whereKey($device->id)->lockForUpdate()->first();
                if ($current === null || (int) $lockedEvent->device_id !== (int) $device->id
                    || $lockedEvent->company_id === null || $lockedEvent->branch_id === null
                    || ($reviewId === null && ((int) $current->company_id !== (int) $lockedEvent->company_id
                        || (int) $current->branch_id !== (int) $lockedEvent->branch_id))
                    || (int) $device->company_id !== (int) $lockedEvent->company_id
                    || (int) $device->branch_id !== (int) $lockedEvent->branch_id) {
                    $lockedEvent->update(['ack_status' => SyncEvent::STATUS_NEEDS_REVIEW,
                        'result_json' => ['error' => 'identity_mismatch', 'permanent' => true]]);

                    return null;
                }
                $device->syncIntegrityFlags = [];
                $result = $handler->handle($lockedEvent, $device);
                $this->flagClockBehind($lockedEvent, $device);
                if ($device->syncIntegrityFlags !== []) {
                    $result['integrity_flags'] = array_values(array_unique($device->syncIntegrityFlags));
                }
                $lockedEvent->update([
                    'ack_status' => SyncEvent::STATUS_PROCESSED,
                    'processed_at' => now(),
                    'result_json' => $result,
                ]);

                return $result;
            }, 5);
        } catch (Throwable $e) {
            // The handler effect and attempted processed stamp have both
            // rolled back. Re-lock before recording the durable rejection:
            // a waiting contender may have processed the event after our
            // rollback released its row lock, and processed is terminal.
            $eventExists = DB::transaction(function () use ($event, $e): bool {
                $lockedEvent = SyncEvent::query()
                    ->whereKey($event->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedEvent === null) {
                    return false;
                }

                if (in_array($lockedEvent->ack_status, [
                    SyncEvent::STATUS_RECEIVED,
                    SyncEvent::STATUS_FAILED,
                ], true)) {
                    $refusal = $e instanceof QrDineInException
                        && in_array($lockedEvent->event_type, ['table.session.cancel_line', 'table.session.cancel_bill'], true)
                            ? ['refusal_code' => $e->codeName] + $e->details
                            : [];
                    $lockedEvent->update([
                        'ack_status' => SyncEvent::STATUS_FAILED,
                        'processed_at' => now(),
                        // LAUNCH-P5 — a SyncRefusal carries a machine-readable
                        // code and details (e.g. unsynced_sales + missing).
                        'result_json' => $e instanceof SyncRefusal ? $e->resultJson() : ['error' => $e instanceof PDOException
                            ? self::TRANSIENT_ERROR
                            : $e->getMessage()] + $refusal,
                    ]);
                }

                return true;
            });

            if ($eventExists) {
                $event->refresh();
            }

            return;
        }

        // A contender that waited for an already-processed winner returns
        // without post-commit work or a second broadcast. Refresh the caller's
        // model so its ACK reflects that durable winner.
        $event->refresh();
        if ($settlement === null) {
            return;
        }

        // A reviewed replay settles historical evidence only. Post-commit
        // hooks and broadcasts would reintroduce it into today's live workflow.
        if ($reviewId !== null) {
            return;
        }
        $result = $settlement;

        // External work must never run before the effect + ACK transaction
        // commits, and must never turn an already-processed event failed.
        if ($handler instanceof AfterSyncEventCommitHandler) {
            try {
                $handler->afterSyncEventCommit($event, $device, $result);
            } catch (Throwable $e) {
                Log::warning('sync event post-commit hook failed', [
                    'event_id' => $event->getKey(),
                    'event_type' => $event->event_type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // §11.5 — real-time push to the branch's other terminals (a second
        // register, a handheld, the kitchen display) the moment the event
        // settles. The event is already durably `processed` and the device has
        // its ACK, so a live-push failure (Reverb down) must NOT fail it: this
        // is best-effort and swallowed, and sits OUTSIDE the handler try/catch
        // above (which would otherwise re-stamp a good event `failed`).
        //
        // event() routes through the framework dispatcher — it still broadcasts
        // the ShouldBroadcastNow event in prod, and stays assertable under
        // Event::fake() in tests.
        try {
            event(DeviceSyncBroadcast::fromProcessed($event, $device));
        } catch (Throwable $e) {
            if (Cache::add('reverb-publish-warn:'.$e::class, true, 60)) {
                Log::warning('reverb publish failed', [
                    'event_id' => (int) $event->getKey(),
                    'event_type' => (string) $event->event_type,
                    'branch_id' => $device->branch_id !== null ? (int) $device->branch_id : null,
                    'device_id' => (int) $device->getKey(),
                    'error' => $e->getMessage(),
                ]);
                report($e);
            }

            // best-effort; the domain event stands regardless of push delivery
        }
    }

    /**
     * LAUNCH-P3 fix order 1 M2 — an event whose client_timestamp is more than
     * 24 hours behind the time the server received it is flagged
     * (result.integrity_flags, additive) and logged: either a long offline
     * backlog or a device clock running behind, which dates stock and picks
     * the recipe in force at that time. The event still settles.
     */
    private function flagClockBehind(SyncEvent $event, Device $device): void
    {
        if ($event->client_timestamp === null || $event->server_received_at === null) {
            return;
        }
        $behind = $event->server_received_at->getTimestamp() - $event->client_timestamp->getTimestamp();
        if ($behind <= self::CLIENT_TIME_BEHIND_FLAG_HOURS * 3600) {
            return;
        }

        $device->syncIntegrityFlags[] = self::CLIENT_TIME_BEHIND_FLAG;
        try {
            Log::warning('LAUNCH-P3: a device event is stamped more than 24 h before it was received', [
                'event_id' => (int) $event->getKey(),
                'event_type' => (string) $event->event_type,
                'device_id' => (int) $device->getKey(),
                'client_timestamp' => $event->client_timestamp->copy()->utc()->toIso8601String(),
                'server_received_at' => $event->server_received_at->copy()->utc()->toIso8601String(),
                'behind_seconds' => $behind,
            ]);
        } catch (Throwable) {
            // Best-effort; the event settles regardless.
        }
    }
}
