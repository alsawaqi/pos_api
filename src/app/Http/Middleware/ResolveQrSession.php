<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\QrSession;
use App\Support\QrApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Resolve and authenticate X-QR-Session + X-QR-Client-Secret. */
class ResolveQrSession
{
    public function handle(Request $request, Closure $next, string $mode = 'open'): Response
    {
        $uuid = trim((string) $request->header('X-QR-Session', ''));
        $clientSecret = (string) $request->header('X-QR-Client-Secret', '');

        if ($uuid === '' || $clientSecret === '') {
            return $this->notFound();
        }

        $session = DB::transaction(function () use ($uuid, $clientSecret, $mode): ?QrSession {
            $session = QrSession::query()
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->first();

            if ($session === null || $session->released_at !== null) {
                return null;
            }

            $now = now();
            if ($session->isExpiredAt($now)) {
                // The where clauses make this a safe one-way transition even
                // if another request reached the same deadline concurrently.
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

            $credentialStatuses = QrSession::CREDENTIAL_STATUSES;
            if ($mode === 'include-closed') {
                $credentialStatuses[] = QrSession::STATUS_CLOSED;
            }

            if (! in_array($session->status, $credentialStatuses, true)
                || ! $session->clientSecretMatches($clientSecret)) {
                return null;
            }

            $device = Device::withTrashed()->whereKey($session->device_id)->first();
            if ($session->device_id === null) {
                if ($session->origin !== 'table_card' || $session->table_id === null) {
                    return null;
                }
            } elseif ($device === null
                || $device->trashed()
                || $device->status !== 'active'
                || ! $device->isAssigned()
                || ! $device->isPaymentStation()
                || (int) $device->company_id !== (int) $session->company_id
                || (int) $device->branch_id !== (int) $session->branch_id) {
                return null;
            }

            $session->update(['last_seen_at' => $now]);

            return $session->fresh();
        });

        if ($session === null) {
            return $this->notFound();
        }

        $request->attributes->set('qr_session', $session);

        return $next($request);
    }

    private function notFound(): JsonResponse
    {
        return QrApiResponse::failure(
            'qr_session_not_found',
            'QR session was not found.',
            404,
        );
    }
}
