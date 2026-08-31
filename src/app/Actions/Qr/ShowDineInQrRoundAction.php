<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;

/** Returns the staff-visible frozen round projection under canonical locks. */
final class ShowDineInQrRoundAction
{
    public function __construct(
        private readonly WithLockedDineInQrRoundAction $locked,
        private readonly PresentDeviceQrRoundAction $present,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Device $device, int $roundId): array
    {
        return $this->locked->handle(
            $device,
            $roundId,
            fn (Order $order, QrSession $session, QrOrderRound $round): array => $this->present->handle(
                $order,
                $session,
                $round,
            ),
        );
    }
}
