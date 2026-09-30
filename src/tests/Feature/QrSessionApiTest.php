<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class QrSessionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A test-only consumer isolates the credential middleware from the
        // menu, order and status controllers.
        Route::get('api/v1/public/qr/session-probe', function (Request $request) {
            /** @var QrSession $session */
            $session = $request->attributes->get('qr_session');

            return response()->json([
                'data' => ['session_uuid' => $session->uuid, 'status' => $session->status],
                'errors' => [],
            ]);
        })->middleware('qr.session');
    }

    private function device(
        string $token,
        string $type = 'payment_station',
        array $attributes = [],
    ): Device {
        return Device::factory()->paired($token)->create($attributes + [
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => $type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function qrSession(Device $device, array $attributes = []): QrSession
    {
        $now = now();

        return QrSession::query()->create($attributes + [
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->getKey(),
            'token' => Str::random(64),
            'token_expires_at' => $now->copy()->addMinute(),
            'status' => QrSession::STATUS_PENDING,
            'expires_at' => $now->copy()->addMinutes(30),
        ]);
    }

    private function rotate(string $deviceToken): TestResponse
    {
        return $this->withToken($deviceToken)->postJson('/api/v1/device/qr/rotate');
    }

    private function bind(string $token, string $clientSecret, ?string $ip = null): TestResponse
    {
        $test = $ip === null ? $this : $this->withServerVariables(['REMOTE_ADDR' => $ip]);

        return $test->postJson('/api/v1/public/qr/bind', [
            'token' => $token,
            'client_secret' => $clientSecret,
        ]);
    }

    private function probe(QrSession $session, string $clientSecret): TestResponse
    {
        return $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $clientSecret,
        ])->getJson('/api/v1/public/qr/session-probe');
    }

    public function test_fix1_bind_and_status_include_the_same_public_branding_without_loading_menu(): void
    {
        $device = $this->device('brand-release');
        DB::table('pos_companies')->where('id', 100)->update(['name' => 'Binding Bakery', 'status' => 'onboarding']);
        $session = $this->qrSession($device);
        $secret = str_repeat('a', 64);
        $bound = $this->bind($session->token, $secret)->assertOk();
        $bound->assertJsonPath('data.branding.merchant.name', 'Binding Bakery');
        $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => $secret])
            ->getJson('/api/v1/public/qr/status')->assertOk()
            ->assertJsonPath('data.branding', $bound->json('data.branding'));
    }

    public function test_default_quick_lifetime_is_two_hours_with_one_minute_token_rotation(): void
    {
        $now = Carbon::parse('2026-09-07 12:00:00');
        $this->travelTo($now);

        $this->assertSame(120, config('qr.session_lifetime_minutes'));
        $this->assertSame(60, config('qr.token_rotation_seconds'));
        $this->device('station-default-lifetime');
        $this->rotate('station-default-lifetime')->assertOk();

        $session = QrSession::query()->sole();
        $this->assertTrue($session->created_at->equalTo($now));
        $this->assertTrue($session->expires_at->equalTo($now->copy()->addMinutes(120)));
        $this->assertTrue($session->token_expires_at->equalTo($now->copy()->addSeconds(60)));
    }

    public function test_only_an_active_assigned_payment_station_can_rotate(): void
    {
        $now = Carbon::parse('2026-08-26 12:00:00');
        $this->travelTo($now);
        config()->set('qr.token_rotation_seconds', 60);
        config()->set('qr.session_lifetime_minutes', 30);

        $this->device('station-rotate');
        $response = $this->rotate('station-rotate')->assertOk();

        $session = QrSession::query()->sole();
        $this->assertSame(QrSession::STATUS_PENDING, $session->status);
        $this->assertSame(64, strlen((string) $response->json('data.token')));
        $this->assertSame($session->token, $response->json('data.token'));
        $this->assertTrue($session->token_expires_at->equalTo($now->copy()->addSeconds(60)));
        $this->assertTrue($session->expires_at->equalTo($now->copy()->addMinutes(30)));
        $this->assertNull($session->client_secret_hash);

        foreach (['pos_terminal', 'handheld'] as $index => $type) {
            $token = 'non-station-'.$index;
            $this->device($token, $type);
            $this->app['auth']->forgetGuards();

            $this->rotate($token)
                ->assertStatus(409)
                ->assertJsonPath('errors.0.code', 'device_unassigned');
        }

        $this->assertDatabaseCount('pos_qr_sessions', 1);
    }

    public function test_rotation_expires_every_previous_pending_row_and_stamps_closed_at(): void
    {
        $now = Carbon::parse('2026-08-26 12:00:00');
        $this->travelTo($now);
        $device = $this->device('station-rerotate');
        $older = $this->qrSession($device);
        $previous = $this->qrSession($device);

        $rotatedAt = $now->copy()->addSeconds(10);
        $this->travelTo($rotatedAt);
        $this->rotate('station-rerotate')->assertOk();

        foreach ([$older, $previous] as $expired) {
            $expired->refresh();
            $this->assertSame(QrSession::STATUS_EXPIRED, $expired->status);
            $this->assertTrue($expired->closed_at->equalTo($rotatedAt));
        }

        $this->assertSame(1, QrSession::query()->where('status', QrSession::STATUS_PENDING)->count());
    }

    public function test_first_scan_binds_and_only_the_winning_secret_can_use_the_session(): void
    {
        $this->device('station-bind');
        $token = (string) $this->rotate('station-bind')->assertOk()->json('data.token');
        $secret = 'first-phone-secret';

        $bound = $this->bind($token, $secret)
            ->assertOk()
            ->assertJsonPath('data.status', QrSession::STATUS_ACTIVE);

        $session = QrSession::query()->sole();
        $this->assertSame($session->uuid, $bound->json('data.session_uuid'));
        $this->assertSame(QrSession::STATUS_ACTIVE, $session->status);
        $this->assertSame(QrSession::hashClientSecret($secret), $session->client_secret_hash);
        $this->assertNotSame($secret, $session->client_secret_hash);
        $this->assertNotNull($session->bound_at);
        $this->assertNotNull($session->last_seen_at);

        $refused = $this->bind($token, 'second-phone-secret')
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_bind_failed');

        $this->probe($session, $secret)
            ->assertOk()
            ->assertJsonPath('data.session_uuid', $session->uuid);
        $this->assertDatabaseCount('pos_qr_sessions', 1);
        $this->assertSame('qr_bind_failed', $refused->json('errors.0.code'));
    }

    public function test_same_secret_bind_replay_is_idempotent(): void
    {
        $this->device('station-replay');
        $token = (string) $this->rotate('station-replay')->assertOk()->json('data.token');

        $first = $this->bind($token, 'same-phone-secret')->assertOk();
        $replay = $this->bind($token, 'same-phone-secret')->assertOk();

        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertDatabaseCount('pos_qr_sessions', 1);
    }

    public function test_client_secret_is_an_opaque_value_whose_whitespace_is_preserved(): void
    {
        $this->device('station-opaque-secret');
        $token = (string) $this->rotate('station-opaque-secret')->assertOk()->json('data.token');
        $secret = '  opaque-phone-secret  ';

        $this->bind($token, $secret)->assertOk();

        $session = QrSession::query()->sole();
        $this->assertSame(QrSession::hashClientSecret($secret), $session->client_secret_hash);
        $this->probe($session, $secret)->assertOk();
        $this->probe($session, trim($secret))->assertNotFound();
    }

    public function test_expired_rotation_refuses_and_hard_expiry_is_lazily_closed(): void
    {
        $now = Carbon::parse('2026-08-26 12:00:00');
        $this->travelTo($now);
        $device = $this->device('station-expiry');

        $expiredRotation = $this->qrSession($device, [
            'token_expires_at' => $now->copy()->subSecond(),
            'expires_at' => $now->copy()->addMinute(),
        ]);
        $this->bind($expiredRotation->token, 'expiry-phone-secret')->assertNotFound();
        $this->assertSame(QrSession::STATUS_PENDING, $expiredRotation->fresh()->status);

        $secret = 'hard-expiry-secret';
        $hardExpired = $this->qrSession($device, [
            'status' => QrSession::STATUS_ACTIVE,
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'bound_at' => $now->copy()->subMinutes(31),
            'expires_at' => $now->copy()->subSecond(),
        ]);

        $this->probe($hardExpired, $secret)
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');

        $hardExpired->refresh();
        $this->assertSame(QrSession::STATUS_EXPIRED, $hardExpired->status);
        $this->assertTrue($hardExpired->closed_at->equalTo($now));
    }

    public function test_every_bind_credential_failure_has_one_identical_response(): void
    {
        $now = Carbon::parse('2026-08-26 12:00:00');
        $this->travelTo($now);
        $station = $this->device('station-uniform');

        $expiredToken = $this->qrSession($station, [
            'token_expires_at' => $now->copy()->subSecond(),
        ]);
        $alreadyBound = $this->qrSession($station, [
            'status' => QrSession::STATUS_ACTIVE,
            'client_secret_hash' => QrSession::hashClientSecret('winning-secret'),
            'bound_at' => $now,
        ]);

        $nonStationDevice = $this->device('terminal-uniform', 'pos_terminal');
        $nonStation = $this->qrSession($nonStationDevice);

        $inactiveDevice = $this->device('inactive-uniform');
        $inactive = $this->qrSession($inactiveDevice);
        $inactiveDevice->update(['status' => 'inactive']);

        $deletedDevice = $this->device('deleted-uniform');
        $deleted = $this->qrSession($deletedDevice);
        $deletedDevice->delete();

        $unassignedDevice = $this->device('unassigned-uniform');
        $unassigned = $this->qrSession($unassignedDevice);
        $unassignedDevice->forceFill(['company_id' => null, 'branch_id' => null])->save();

        $attempts = [
            [str_repeat('u', 64), 'unknown-secret'],
            [$expiredToken->token, 'expired-secret'],
            [$alreadyBound->token, 'different-secret'],
            [$nonStation->token, 'non-station-secret'],
            [$inactive->token, 'inactive-secret'],
            [$deleted->token, 'deleted-secret'],
            [$unassigned->token, 'unassigned-secret'],
        ];

        $bodies = [];
        foreach ($attempts as [$token, $secret]) {
            $response = $this->bind($token, $secret)->assertNotFound();
            $bodies[] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies));
        $this->assertSame([
            'data' => null,
            'errors' => [[
                'code' => 'qr_bind_failed',
                'message' => 'QR session could not be bound.',
            ]],
        ], json_decode($bodies[0], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_credential_resolver_accepts_active_and_ordered_and_touches_last_seen(): void
    {
        $now = Carbon::parse('2026-08-26 12:00:00');
        $this->travelTo($now);
        $device = $this->device('station-resolver');
        $secret = 'resolver-secret';
        $session = $this->qrSession($device, [
            'status' => QrSession::STATUS_ACTIVE,
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'bound_at' => $now->copy()->subMinute(),
            'last_seen_at' => $now->copy()->subMinute(),
        ]);

        $this->probe($session, $secret)
            ->assertOk()
            ->assertJsonPath('data.status', QrSession::STATUS_ACTIVE);
        $this->assertTrue($session->fresh()->last_seen_at->equalTo($now));

        $later = $now->copy()->addSecond();
        $this->travelTo($later);
        $session->update(['status' => QrSession::STATUS_ORDERED]);
        $this->probe($session, $secret)
            ->assertOk()
            ->assertJsonPath('data.status', QrSession::STATUS_ORDERED);
        $this->assertTrue($session->fresh()->last_seen_at->equalTo($later));

        $this->probe($session, 'wrong-secret')->assertNotFound();
    }

    public function test_bind_limiter_uses_the_public_error_envelope(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->bind(str_repeat('z', 64), 'rate-limit-secret')->assertNotFound();
        }

        $this->bind(str_repeat('z', 64), 'rate-limit-secret')
            ->assertStatus(429)
            ->assertExactJson([
                'data' => null,
                'errors' => [[
                    'code' => 'rate_limited',
                    'message' => 'Too many requests.',
                ]],
            ]);
    }

    public function test_bind_limiter_caps_one_submitted_token_across_source_ips(): void
    {
        $token = str_repeat('d', 64);
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->bind($token, 'distributed-secret', '192.0.2.'.$attempt)->assertNotFound();
        }

        $this->bind($token, 'distributed-secret', '192.0.2.11')
            ->assertStatus(429)
            ->assertExactJson([
                'data' => null,
                'errors' => [[
                    'code' => 'rate_limited',
                    'message' => 'Too many requests.',
                ]],
            ]);
    }
}
