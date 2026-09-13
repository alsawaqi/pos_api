<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Orders\VoidOrderCoreAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\PaymentReversal;
use App\Models\VoidReason;
use stdClass;

final class ApplyReversalOrderEffectsAction
{
    public function __construct(
        private readonly VoidOrderCoreAction $voidCore,
        private readonly ReturnRefundedUnitStockAction $stock,
    ) {}

    public function void(stdClass $order, PaymentReversal $reversal): void
    {
        $this->voidCore->handle(
            Order::query()->findOrFail($order->id),
            Device::withoutGlobalScopes()->findOrFail($reversal->device_id),
            now(),
            'VOID (card reversal '.$reversal->uuid.')',
            VoidReason::withTrashed()->findOrFail($reversal->void_reason_id),
        );
    }

    public function refund(stdClass $order, PaymentReversal $reversal, bool $full): void
    {
        $this->stock->handle($reversal);
        if ($full) {
            $this->voidCore->reverseLoyalty(Order::query()->findOrFail($order->id), now(), true);
        }
    }
}
