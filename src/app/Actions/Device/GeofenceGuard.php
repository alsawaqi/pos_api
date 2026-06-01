<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Branch;
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
    private const TOLERANCE_M = 100.0;

    private const EARTH_RADIUS_M = 6_371_000.0;

    private const DEFAULT_RADIUS_M = 500;

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

    private function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * asin(min(1.0, sqrt($a)));
    }
}
