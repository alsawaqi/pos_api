<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Device;

/**
 * §11.5 — authorizes a device to join its branch channel (branch.{id}).
 *
 * The branch operational stream — the main POS / handheld / KDS feed. A device
 * may only join the channel for the branch it is paired to; a branchless device
 * joins none. See {@see CompanyChannel} for why this is a class, not a closure.
 */
final class BranchChannel
{
    public function join(Device $device, int $branchId): bool
    {
        return $device->branch_id !== null && (int) $device->branch_id === $branchId;
    }
}
