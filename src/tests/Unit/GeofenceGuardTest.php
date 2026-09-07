<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\Device\GeofenceGuard;
use App\Models\Branch;
use RuntimeException;
use Tests\TestCase;

class GeofenceGuardTest extends TestCase
{
    public function test_distance_matches_two_hand_computed_great_circle_arcs(): void
    {
        $guard = new GeofenceGuard;
        $branch = new Branch(['latitude' => 0, 'longitude' => 0]);
        $this->assertEqualsWithDelta(111194.92664455874, $guard->distanceMetres($branch, 0, 1), 0.000001);
        $this->assertEqualsWithDelta(10007543.398010286, $guard->distanceMetres($branch, 90, 0), 0.000001);
        $this->assertSame(500, $guard->radiusMetres($branch));
        $branch->geofence_radius_m = 250;
        $this->assertSame(250, $guard->radiusMetres($branch));
    }

    public function test_unfenced_distance_is_null_and_existing_admission_is_unchanged(): void
    {
        $guard = new GeofenceGuard;
        foreach ([[null, 0], [0, null], [null, null]] as [$lat, $lng]) {
            $branch = new Branch(['latitude' => $lat, 'longitude' => $lng]);
            $this->assertFalse($guard->isFenced($branch));
            $this->assertNull($guard->distanceMetres($branch, 23, 58));
            $this->assertTrue($guard->isWithin($branch, 23, 58));
            $guard->assertWithin($branch, 23, 58);
        }
    }

    public function test_public_distance_and_existing_fence_agree_one_metre_either_side_of_tolerance(): void
    {
        $guard = new GeofenceGuard;
        $branch = new Branch(['latitude' => 0, 'longitude' => 0, 'geofence_radius_m' => 500]);
        foreach ([599 => true, 601 => false] as $metres => $inside) {
            $lat = rad2deg($metres / 6371000);
            $distance = $guard->distanceMetres($branch, $lat, 0);
            $this->assertEqualsWithDelta($metres, $distance, 0.000001);
            $this->assertSame($inside, $guard->isWithin($branch, $lat, 0));
            $this->assertSame($inside, $distance <= $guard->radiusMetres($branch) + GeofenceGuard::TOLERANCE_M);
        }
    }

    public function test_existing_device_exception_reports_the_public_distance_without_changing_its_copy(): void
    {
        $guard = new GeofenceGuard;
        $branch = new Branch(['latitude' => 0, 'longitude' => 0, 'geofence_radius_m' => 500]);
        $distance = $guard->distanceMetres($branch, 0, 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'order rejected: device is %dm from the branch (geofence 500m + 100m tolerance)',
            (int) round($distance),
        ));
        $guard->assertWithin($branch, 0, 1);
    }
}
