<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Device;

/**
 * §11.5 — authorizes a device to join its company-wide channel (company.{id}).
 *
 * A device may only join the channel for the company it is paired to. Extracted
 * as a channel class (rather than an inline closure in routes/channels.php) so
 * the authorization predicate is directly unit-testable, independent of the
 * broadcast driver — the test broadcaster is `null`, whose auth() is a no-op,
 * so the HTTP /broadcasting/auth endpoint can't exercise this logic.
 */
final class CompanyChannel
{
    public function join(Device $device, int $companyId): bool
    {
        return (int) $device->company_id === $companyId;
    }
}
