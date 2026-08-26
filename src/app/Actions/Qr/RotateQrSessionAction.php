<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\QrSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RotateQrSessionAction
{
    public function handle(Device $authenticatedDevice): QrSession
    {
        return DB::transaction(function () use ($authenticatedDevice): QrSession {
            // Lock the device, not merely the prior session. There is no unique
            // partial index for one pending row, so this serialises two rotate
            // requests before either can mint a replacement.
            $device = Device::query()
                ->whereKey($authenticatedDevice->getKey())
                ->lockForUpdate()
                ->first();

            if ($device === null
                || $device->status !== 'active'
                || ! $device->isAssigned()
                || ! $device->isPaymentStation()) {
                throw new RuntimeException('device cannot rotate QR sessions');
            }

            $now = now();

            QrSession::query()
                ->where('device_id', $device->getKey())
                ->where('status', QrSession::STATUS_PENDING)
                ->update([
                    'status' => QrSession::STATUS_EXPIRED,
                    'closed_at' => $now,
                    'updated_at' => $now,
                ]);

            $tokenSeconds = max(1, (int) config('qr.token_rotation_seconds', 60));
            $sessionMinutes = max(1, (int) config('qr.session_lifetime_minutes', 30));

            return QrSession::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'device_id' => $device->getKey(),
                'token' => bin2hex(random_bytes(32)),
                'token_expires_at' => $now->copy()->addSeconds($tokenSeconds),
                'status' => QrSession::STATUS_PENDING,
                'expires_at' => $now->copy()->addMinutes($sessionMinutes),
            ]);
        });
    }
}
