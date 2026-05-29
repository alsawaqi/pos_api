<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Actions\Device\IngestSyncEventsAction;
use App\Actions\Device\Sync\Handlers\CloseShiftHandler;
use App\Actions\Device\Sync\Handlers\CreateOrderHandler;
use App\Actions\Device\Sync\Handlers\ExpenseLogHandler;
use App\Actions\Device\Sync\Handlers\OpenShiftHandler;
use App\Actions\Device\Sync\Handlers\PayOrderHandler;
use App\Actions\Device\Sync\Handlers\RestockRequestHandler;
use App\Actions\Device\Sync\Handlers\VoidOrderHandler;
use App\Models\Device;
use App\Models\SyncEvent;
use Throwable;

/**
 * Phase 8.3 — the processing seam 8.2 deferred.
 *
 * After {@see IngestSyncEventsAction} records a NEW
 * event (ack_status=received), it hands the row here. We route by
 * event_type to the registered handler, run it, and stamp the event
 * processed (with the handler's result_json) or failed (with the error) —
 * which the per-event ACK then reflects. Event types without a handler
 * (donation.record, expense.log, shift.*, …) stay `received` for a later
 * sub-phase. Replayed/duplicate events never reach here (deduped upstream),
 * so processing runs exactly once per event.
 */
class SyncEventDispatcher
{
    public function __construct(
        private readonly CreateOrderHandler $createOrder,
        private readonly PayOrderHandler $payOrder,
        private readonly VoidOrderHandler $voidOrder,
        private readonly OpenShiftHandler $openShift,
        private readonly CloseShiftHandler $closeShift,
        private readonly ExpenseLogHandler $expenseLog,
        private readonly RestockRequestHandler $restockRequest,
    ) {}

    public function dispatch(SyncEvent $event, Device $device): void
    {
        $handler = match ($event->event_type) {
            'order.create' => $this->createOrder,
            'order.pay' => $this->payOrder,
            'order.void' => $this->voidOrder,
            'shift.open' => $this->openShift,
            'shift.close' => $this->closeShift,
            'expense.log' => $this->expenseLog,
            'restock.request' => $this->restockRequest,
            default => null,
        };

        if ($handler === null) {
            return;
        }

        try {
            $result = $handler->handle($event, $device);
            $event->update([
                'ack_status' => SyncEvent::STATUS_PROCESSED,
                'processed_at' => now(),
                'result_json' => $result,
            ]);
        } catch (Throwable $e) {
            $event->update([
                'ack_status' => SyncEvent::STATUS_FAILED,
                'processed_at' => now(),
                'result_json' => ['error' => $e->getMessage()],
            ]);
        }
    }
}
