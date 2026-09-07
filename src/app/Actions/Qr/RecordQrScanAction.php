<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\QrSession;
use App\Models\QrSessionScan;
use App\Models\Table;

/** Tenant-resolved scan audit only; never retain raw IP, fingerprint or identity. */
final class RecordQrScanAction
{
    public function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    public function handle(
        Table $table,
        int $branchId,
        ?QrSession $session,
        ?int $seatingId,
        string $role,
        string $outcome,
        array $scan,
        array $geofence,
        string $ip,
    ): QrSessionScan {
        $location = ($scan['location_state'] ?? null) === 'granted' ? ($scan['location'] ?? null) : null;

        return QrSessionScan::query()->create([
            'company_id' => (int) $table->company_id, 'branch_id' => $branchId,
            'table_id' => $table->id, 'qr_session_id' => $role === 'owner' ? $session?->id : null,
            'table_session_id' => $seatingId, 'role' => $role, 'outcome' => $outcome,
            'device_fingerprint_hash' => $scan['fingerprint_hash'] ?? null,
            'ip_hash' => $this->ipHash($ip),
            'latitude' => $location['lat'] ?? null, 'longitude' => $location['lng'] ?? null,
            'accuracy_m' => $location['accuracy_m'] ?? null,
            'distance_m' => $geofence['distance_m'], 'geofence_verdict' => $geofence['verdict'],
            'scanned_at' => now(), 'created_at' => now(),
        ]);
    }
}
