<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Device\GeofenceGuard;
use App\Models\Branch;

final class ScanGeofence
{
    public function __construct(private readonly GeofenceGuard $guard) {}

    /** @return array{verdict: string, distance_m: ?int} */
    public function verdict(Branch $branch, string $mode, string $locationState, ?array $location): array
    {
        $fenced = $this->guard->isFenced($branch);
        $granted = $locationState === 'granted' && $location !== null;
        $distance = $fenced && $granted
            ? (int) round($this->guard->distanceMetres($branch, (float) $location['lat'], (float) $location['lng']))
            : null;
        $verdict = match (true) {
            $mode === 'off' => 'off',
            ! $fenced => 'unfenced',
            $granted => $this->guard->isWithin($branch, (float) $location['lat'], (float) $location['lng']) ? 'inside' : 'outside',
            $locationState === 'denied' => 'refused',
            default => 'unknown',
        };

        return ['verdict' => $verdict, 'distance_m' => $distance];
    }
}
