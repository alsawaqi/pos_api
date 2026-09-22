<?php

namespace App\Providers;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Device;
use App\Models\QrSession;
use App\Models\Table;
use App\Support\Qr\ForwardedCustomerIp;
use App\Support\QrApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AppendTableSessionEventAction::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app['events']->listen(TransactionCommitting::class, function (TransactionCommitting $event): void {
            $this->app->make(AppendTableSessionEventAction::class)->committing($event->connection);
        });
        $this->app['events']->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            $logicalBoundary = $this->app->bound('db.transactions')
                && $this->app->make('db.transactions')->afterCommitCallbacksShouldBeExecuted($event->connection->transactionLevel());
            $this->app->make(AppendTableSessionEventAction::class)->committed($event->connection, $logicalBoundary);
        });
        $this->app['events']->listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            $this->app->make(AppendTableSessionEventAction::class)->rolledBack($event->connection);
        });

        // Phase 8 — the device guard. A paired terminal authenticates by
        // presenting its long-lived device_token as a Bearer credential;
        // we resolve it straight off the shared pos_devices table. No
        // Sanctum — the token is a column, not a personal-access-token.
        // SoftDeletes excludes deleted rows; the status filter additionally
        // rejects REVOKED devices whose token column is still set — 'blocked'
        // (decommissioned/suspended by the admin) and 'inactive' (disabled).
        // A paired, operable device is 'active'; pre-pairing states
        // ('registered'/'assigned') carry no token so never reach here.
        // Statuses mirror pos_admin's DeviceStatus enum.
        Auth::viaRequest('pos_device', function (Request $request): ?Device {
            $token = $request->bearerToken();
            if ($token === null || $token === '') {
                return null;
            }

            return Device::query()
                ->where('device_token', $token)
                ->whereNotIn('status', ['blocked', 'inactive'])
                ->first();
        });

        // Rate limiters (blueprint §12 hardening). Pairing is the brute-force
        // surface — an attacker guessing one-time activation tokens — so it is
        // throttled hard, both per-IP and per-kiosk. Authenticated device
        // traffic (heartbeat, sync) gets a generous per-device budget keyed
        // off the resolved device id so one noisy terminal can't starve the
        // rest of the fleet (and falls back to IP before the guard resolves).
        RateLimiter::for('device-pair', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.self::rateLimitIpKey($request->ip())),
            Limit::perMinute(20)->by('kiosk:'.(string) $request->input('kiosk_id')),
        ]);

        RateLimiter::for('device-api', fn (Request $request) => Limit::perMinute(120)
            ->by('device:'.(string) ($request->user()?->getAuthIdentifier() ?? self::rateLimitIpKey($request->ip()))));

        $qrRateLimited = static function (Request $request, array $headers) {
            $response = QrApiResponse::failure('rate_limited', 'Too many requests.', 429);
            $response->headers->add($headers);

            return $response;
        };
        $forwardedCustomerIp = app(ForwardedCustomerIp::class);

        // A station polls while a customer is waiting to tap. Twenty requests
        // per minute covers a three-second poll; 60 leaves reconnect headroom
        // while keeping this database-backed read bounded per station.
        RateLimiter::for('qr-station-read', fn (Request $request) => Limit::perMinute(60)
            ->by('qr-station-read:device:'.(string) $request->user()?->getAuthIdentifier())
            ->response($qrRateLimited));

        RateLimiter::for('qr-bind', function (Request $request) use ($forwardedCustomerIp, $qrRateLimited): array {
            $submittedToken = $request->input('token');
            $token = is_string($submittedToken) ? $submittedToken : '';

            return [
                Limit::perMinute(10)
                    ->by('qr-bind:ip:'.self::rateLimitIpKey($forwardedCustomerIp->resolve($request)))
                    ->response($qrRateLimited),
                Limit::perMinute(10)
                    ->by('qr-bind:token:'.hash('sha256', $token))
                    ->response($qrRateLimited),
            ];
        });

        // Credentialed session buckets are the primary controls. The much
        // higher IP ceilings are anonymous resource backstops kept generous
        // enough that ordinary customers behind shared NAT do not collide.
        RateLimiter::for('qr-read', fn (Request $request) => [
            Limit::perMinute(60)
                ->by('qr-read:session:'.hash('sha256', (string) $request->header('X-QR-Session')))
                ->response($qrRateLimited),
            Limit::perMinute(3000)
                ->by('qr-read:ip:'.self::rateLimitIpKey($forwardedCustomerIp->resolve($request)))
                ->response($qrRateLimited),
        ]);

        RateLimiter::for('qr-quote', fn (Request $request) => [
            Limit::perMinute(30)
                ->by('qr-quote:session:'.hash('sha256', (string) $request->header('X-QR-Session')))
                ->response($qrRateLimited),
            Limit::perMinute(600)
                ->by('qr-quote:ip:'.self::rateLimitIpKey($forwardedCustomerIp->resolve($request)))
                ->response($qrRateLimited),
        ]);

        RateLimiter::for('qr-checkout', fn (Request $request) => [
            Limit::perMinute(10)
                ->by('qr-checkout:ip:'.self::rateLimitIpKey($forwardedCustomerIp->resolve($request)))
                ->response($qrRateLimited),
            Limit::perMinute(10)
                ->by('qr-checkout:session:'.hash('sha256', (string) $request->header('X-QR-Session')))
                ->response($qrRateLimited),
        ]);

        // Printed table menus are sessionless. This is only an anonymous read
        // backstop; table/branch catalogue caching remains the scaling layer.
        RateLimiter::for('qr-table-read', fn (Request $request) => Limit::perMinute(3000)
            ->by('qr-table-read:ip:'.self::rateLimitIpKey($forwardedCustomerIp->resolve($request)))
            ->response($qrRateLimited));

        RateLimiter::for('qr-table-bind', function (Request $request) use ($forwardedCustomerIp, $qrRateLimited): array {
            $submitted = $request->input('table_token');
            $tableToken = is_string($submitted) ? $submitted : '';
            $sessionId = null;
            if ($tableToken !== '' && strlen($tableToken) <= 255) {
                $tableId = Table::query()->where('qr_token', trim($tableToken))->value('id');
                if ($tableId !== null) {
                    $sessionId = QrSession::query()
                        ->where('table_id', (int) $tableId)
                        ->whereIn('status', [
                            QrSession::STATUS_PENDING,
                            QrSession::STATUS_ACTIVE,
                            QrSession::STATUS_ORDERED,
                        ])
                        ->latest('id')
                        ->value('id');
                }
            }
            $credentialKey = $sessionId === null
                ? 'token:'.hash('sha256', $tableToken)
                : 'session:'.(string) $sessionId;

            return [
                // A table may legitimately rejoin many times during a long meal.
                Limit::perHour(60)
                    ->by('qr-table-bind:'.$credentialKey)
                    ->response($qrRateLimited),
                Limit::perMinute(600)
                    ->by('qr-table-bind:ip:'.self::rateLimitIpKey($forwardedCustomerIp->resolve($request)))
                    ->response($qrRateLimited),
            ];
        });

        // Separate from quick checkout: a six-hour table can send many rounds.
        // Twenty/minute per credential permits normal bursts while bounding the
        // full pricing pipeline; the IP axis is a generous NAT-safe backstop.
        RateLimiter::for('qr-dine-in-round', fn (Request $request) => [
            Limit::perMinute(20)
                ->by('qr-dine-in-round:session:'.hash(
                    'sha256',
                    (string) $request->header('X-QR-Session'),
                ))
                ->response($qrRateLimited),
            Limit::perMinute(400)
                ->by('qr-dine-in-round:ip:'.self::rateLimitIpKey($forwardedCustomerIp->resolve($request)))
                ->response($qrRateLimited),
        ]);

        RateLimiter::for('qr-dine-in-finish', fn (Request $request) => [
            Limit::perMinute(10)
                ->by('qr-dine-in-finish:session:'.hash(
                    'sha256',
                    (string) $request->header('X-QR-Session'),
                ))
                ->response($qrRateLimited),
            Limit::perMinute(200)
                ->by('qr-dine-in-finish:ip:'.self::rateLimitIpKey($forwardedCustomerIp->resolve($request)))
                ->response($qrRateLimited),
        ]);

        RateLimiter::for('qr-table-device-read', fn (Request $request) => Limit::perMinute(60)
            ->by('qr-table-device-read:'.(string) $request->user()?->getAuthIdentifier())
            ->response($qrRateLimited));
        RateLimiter::for('loyalty-redeem', fn (Request $request) => $request->input('adjustment.kind') === 'loyalty'
            ? Limit::perMinute(10)->by('loyalty-redeem:'.(string) $request->user()?->getAuthIdentifier())->response($qrRateLimited)
            : Limit::none());
        RateLimiter::for('qr-table-device-write', fn (Request $request) => Limit::perMinute(30)
            ->by('qr-table-device-write:'.(string) $request->user()?->getAuthIdentifier())
            ->response($qrRateLimited));
        RateLimiter::for('qr-settlement-claim', fn (Request $request) => Limit::perMinute(30)
            ->by('qr-settlement-claim:'.(string) $request->user()?->getAuthIdentifier())
            ->response($qrRateLimited));

        // POS staff PIN login is a 6-digit brute-force surface — throttle it
        // hard per-device (the device is already resolved by the guard before
        // this limiter runs), independent of the generous device-api budget.
        RateLimiter::for('pos-login', fn (Request $request) => Limit::perMinute(10)
            ->by('pos-login:'.(string) ($request->user()?->getAuthIdentifier() ?? self::rateLimitIpKey($request->ip()))));
    }

    /**
     * Keep one bucket per IPv4 address and one per native IPv6 /64.
     *
     * IPv4-mapped IPv6 must retain IPv4 cardinality; treating it as native
     * IPv6 would collapse every mapped IPv4 address into the same ::/64.
     */
    private static function rateLimitIpKey(?string $ip): string
    {
        $packed = inet_pton((string) $ip);
        if ($packed === false) {
            return 'unknown';
        }

        if (strlen($packed) === 4) {
            $normalized = inet_ntop($packed);

            return $normalized === false ? 'unknown' : $normalized;
        }

        $mappedIpv4Prefix = str_repeat("\0", 10)."\xff\xff";
        if (str_starts_with($packed, $mappedIpv4Prefix)) {
            $normalized = inet_ntop(substr($packed, 12, 4));

            return $normalized === false ? 'unknown' : $normalized;
        }

        $network = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return $network === false ? 'unknown' : $network.'/64';
    }
}
