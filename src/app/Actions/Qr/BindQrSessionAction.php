<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\QrSession;
use Illuminate\Support\Facades\DB;

final class BindQrSessionAction
{
    /**
     * Bind the pending rotation, or return the already-bound row for an exact
     * same-secret replay. Every refusal is represented by null so the caller
     * cannot accidentally disclose which predicate failed.
     */
    public function handle(string $token, string $clientSecret): ?QrSession
    {
        return DB::transaction(function () use ($token, $clientSecret): ?QrSession {
            $session = QrSession::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return null;
            }

            $now = now();
            if ($session->isExpiredAt($now)) {
                QrSession::query()
                    ->whereKey($session->getKey())
                    ->whereIn('status', QrSession::EXPIRABLE_STATUSES)
                    ->where('expires_at', '<=', $now)
                    ->update([
                        'status' => QrSession::STATUS_EXPIRED,
                        'closed_at' => $now,
                        'updated_at' => $now,
                    ]);

                return null;
            }

            $device = Device::withTrashed()->whereKey($session->device_id)->first();
            if (! $this->isUsableStation($session, $device)) {
                return null;
            }

            // Retrying a bind after a lost response is idempotent only for the
            // phone that proves possession of the same client secret.
            if ($session->status === QrSession::STATUS_ACTIVE
                && $session->clientSecretMatches($clientSecret)) {
                $session->update(['last_seen_at' => $now]);

                return $session->fresh();
            }

            $updated = QrSession::query()
                ->whereKey($session->getKey())
                ->where('status', QrSession::STATUS_PENDING)
                ->whereNull('client_secret_hash')
                ->where('token_expires_at', '>', $now)
                ->where('expires_at', '>', $now)
                ->update([
                    'status' => QrSession::STATUS_ACTIVE,
                    'client_secret_hash' => QrSession::hashClientSecret($clientSecret),
                    'bound_at' => $now,
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);

            return $updated === 1 ? $session->fresh() : null;
        });
    }

    private function isUsableStation(QrSession $session, ?Device $device): bool
    {
        return $device !== null
            && ! $device->trashed()
            && $device->status === 'active'
            && $device->isAssigned()
            && $device->isPaymentStation()
            && (int) $device->company_id === (int) $session->company_id
            && (int) $device->branch_id === (int) $session->branch_id;
    }
}
