<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\QrSession;
use App\Models\Table;
use Illuminate\Support\Facades\DB;

/** First-bind or rebind the live dine-in session behind a durable table token. */
final class BindQrTableSessionAction
{
    /** Every refusal is null so the caller cannot enumerate session state. */
    public function handle(string $tableToken, string $clientSecret): ?QrSession
    {
        return DB::transaction(function () use ($tableToken, $clientSecret): ?QrSession {
            $table = Table::query()
                ->where('qr_token', trim($tableToken))
                ->where('status', 'active')
                ->first();

            if ($table === null) {
                return null;
            }

            $session = QrSession::query()
                ->where('table_id', $table->getKey())
                ->whereIn('status', [
                    QrSession::STATUS_PENDING,
                    QrSession::STATUS_ACTIVE,
                    QrSession::STATUS_ORDERED,
                ])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($session === null || $session->released_at !== null) {
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

            $device = Device::query()
                ->withTrashed()
                ->whereKey($session->device_id)
                ->first();

            if ($session->device_id === null
                ? ($session->origin !== 'table_card' || $session->table_id === null)
                : ! $this->isUsableStation($session, $device)) {
                return null;
            }

            if ($session->status === QrSession::STATUS_ORDERED) {
                return null;
            }

            if ($session->status === QrSession::STATUS_ACTIVE) {
                $session->update([
                    'client_secret_hash' => QrSession::hashClientSecret($clientSecret),
                    'secret_rotated_at' => $now,
                    'last_seen_at' => $now,
                ]);

                return $session->fresh();
            }

            // First-bind keeps today's atomic latch and station gate, but the
            // permanent table credential deliberately has no 60-second token
            // window. The six-hour session horizon is the only time boundary.
            $updated = QrSession::query()
                ->whereKey($session->getKey())
                ->where('status', QrSession::STATUS_PENDING)
                ->whereNull('client_secret_hash')
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

    /** This is deliberately identical to the shipped public QR station gate. */
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
