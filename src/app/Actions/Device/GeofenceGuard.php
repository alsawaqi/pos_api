<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Branch;
use App\Models\Device;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Phase 8.9 — server-side geofence enforcement (blueprint §9.4).
 *
 * An order may only be rung inside its branch's geofence. The device layer is
 * the primary guard (out-of-fence lock screen); this is the defense-in-depth
 * check the blueprint mandates on every order-creating call: recompute the
 * distance from the reported GPS to the branch coordinates and reject beyond
 * the fence radius + a 100 m tolerance for GPS jitter.
 *
 * Enforced only when a GPS fix is supplied — the order.create event stamps the
 * device's location AT ORDER TIME, so a replayed offline order is judged by
 * where it was actually rung, not where the device sits now — and the branch
 * has coordinates configured.
 */
final class GeofenceGuard
{
    /** GPS-jitter tolerance on top of the branch radius (§9.4). */
    public const TOLERANCE_M = 100.0;

    /** No location rule applies to this action. */
    public const SKIP = 'skip';

    /** Today's rule: a GPS fix inside the branch radius + tolerance. */
    public const ENFORCE = 'enforce';

    /**
     * Refuse: the device may only work at its branch, but the branch has no
     * coordinates, so the fence cannot be checked (fail closed, never silent).
     */
    public const BRANCH_LOCATION_MISSING = 'branch_location_missing';

    public const BRANCH_LOCATION_MISSING_MESSAGE = 'this device may only work at its branch, but the branch has no location set; ask support to set the branch location or allow this device at any location';

    private const EARTH_RADIUS_M = 6_371_000.0;

    private const DEFAULT_RADIUS_M = 500;

    /**
     * LAUNCH-P1 decision 2a — which location rule applies to an action this
     * device made at $madeAt (the event's client timestamp; null = now).
     *
     *  - location_mode 'any'  → SKIP: the device may work anywhere.
     *  - location_mode 'branch' (default) → today's rule, EXCEPT an event made
     *    while the device was still 'any' (client timestamp inside
     *    [location_any_started_at, location_mode_since)) is not refused.
     *  - a 'branch' device at a branch WITHOUT coordinates → refuse
     *    (BRANCH_LOCATION_MISSING). Admin never lets a device be or stay
     *    'branch' at such a branch; the migration moved existing ones to 'any'.
     *  - no branch row, or a device object without a location_mode (a reviewed
     *    historical replay snapshot) → the legacy rule: fence only a branch
     *    that has coordinates.
     */
    public function requirement(Device $device, ?Branch $branch, ?CarbonInterface $madeAt = null): string
    {
        $mode = $device->getAttribute('location_mode');
        if ($mode === 'any') {
            return self::SKIP;
        }
        if ($mode !== null && $madeAt !== null && $this->madeWhileAny($device, $madeAt)) {
            return self::SKIP;
        }
        if ($branch === null) {
            return self::SKIP;
        }
        if ($this->isFenced($branch)) {
            return self::ENFORCE;
        }

        return $mode === null ? self::SKIP : self::BRANCH_LOCATION_MISSING;
    }

    private function madeWhileAny(Device $device, CarbonInterface $madeAt): bool
    {
        $since = $device->location_mode_since;
        $anyFrom = $device->location_any_started_at;

        return $since !== null && $anyFrom !== null
            && $madeAt->greaterThanOrEqualTo($anyFrom) && $madeAt->lessThan($since);
    }

    /** True when the branch has coordinates configured (an enforceable fence). */
    public function isFenced(Branch $branch): bool
    {
        return $branch->latitude !== null && $branch->longitude !== null;
    }

    public function assertWithin(Branch $branch, float $lat, float $lng): void
    {
        if ($branch->latitude === null || $branch->longitude === null) {
            return; // no fence configured — nothing to enforce
        }

        $distance = $this->haversineMetres((float) $branch->latitude, (float) $branch->longitude, $lat, $lng);
        $radius = (int) ($branch->geofence_radius_m ?? self::DEFAULT_RADIUS_M);

        if ($distance > $radius + self::TOLERANCE_M) {
            throw new RuntimeException(sprintf(
                'order rejected: device is %dm from the branch (geofence %dm + %dm tolerance)',
                (int) round($distance),
                $radius,
                (int) self::TOLERANCE_M,
            ));
        }
    }

    /**
     * True when the point is inside the branch fence (radius + tolerance), or
     * the branch has no fence configured. The non-throwing companion to
     * {@see assertWithin()} — used by the staff-login geofence check.
     */
    public function isWithin(Branch $branch, float $lat, float $lng): bool
    {
        if ($branch->latitude === null || $branch->longitude === null) {
            return true;
        }

        $distance = $this->haversineMetres((float) $branch->latitude, (float) $branch->longitude, $lat, $lng);
        $radius = (int) ($branch->geofence_radius_m ?? self::DEFAULT_RADIUS_M);

        return $distance <= $radius + self::TOLERANCE_M;
    }

    public function radiusMetres(Branch $branch): int
    {
        return (int) ($branch->geofence_radius_m ?? self::DEFAULT_RADIUS_M);
    }

    public function distanceMetres(Branch $branch, float $lat, float $lng): ?float
    {
        if (! $this->isFenced($branch)) {
            return null;
        }

        return $this->haversineMetres((float) $branch->latitude, (float) $branch->longitude, $lat, $lng);
    }

    private function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * asin(min(1.0, sqrt($a)));
    }
}
