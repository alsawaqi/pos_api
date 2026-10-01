<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LAUNCH-P1 B1 — decision 1a: an activation code works only on the physical
 * device it was made for (hardware serial) and only in the app that matches
 * the device type (P1-10). A refusal never consumes the code, never touches
 * the device's live token or identity, and is recorded for the admin.
 */
class LaunchP1DeviceEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    /** A working, already-activated till plus a fresh code minted for it. */
    private function liveTill(string $code = 'fresh-code'): Device
    {
        $device = Device::factory()->paired('mdev_live_till')->create([
            'serial_number' => 'T3-SN-0042', 'device_type' => 'fixed_pos',
            'token_issued_at' => now()->subDay(), 'assignment_activated_at' => now()->subDay(),
        ]);
        DeviceActivationToken::factory()->for($device)->forPlaintext($code)->create();

        return $device;
    }

    /** @param  array<string, mixed>  $claim */
    private function activate(string $code, array $claim = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/v1/auth/device/activate', ['code' => $code] + $claim);
    }

    private function assertLiveTokenStillWorks(Device $device): void
    {
        $stored = DB::table('pos_devices')->where('id', $device->id)->first();
        $this->assertSame(hash('sha256', 'mdev_live_till'), $stored->device_token);
        $this->assertSame('active', $stored->status);
        $this->assertSame((int) $device->company_id, (int) $stored->token_company_id);
        $this->assertSame((int) $device->branch_id, (int) $stored->token_branch_id);
        Auth::forgetGuards();
        $this->withToken('mdev_live_till')->postJson('/api/v1/device/heartbeat')->assertOk();
    }

    private function assertCodeUnused(string $code): void
    {
        $token = DeviceActivationToken::query()->where('token_hash', DeviceActivationToken::hash($code))->firstOrFail();
        $this->assertNull($token->used_at);
        $this->assertNull($token->revoked_at);
    }

    public function test_matching_serial_activates_and_marks_the_serial_verified(): void
    {
        $device = $this->liveTill();

        $this->activate('fresh-code', ['serial' => 'T3-SN-0042', 'app' => 'till', 'manufacturer' => 'SUNMI', 'model' => 'T3 PRO'])
            ->assertOk()
            ->assertJsonPath('data.device.uuid', $device->uuid)
            ->assertJsonPath('data.device.location_mode', 'branch');

        $this->assertNotNull($device->fresh()->serial_verified_at);
        $this->assertDatabaseCount('pos_device_activation_attempts', 0);
    }

    public function test_serials_are_compared_after_trimming_removing_spaces_and_upper_casing(): void
    {
        $device = $this->liveTill();

        $this->activate('fresh-code', ['serial' => "  t3-sn-00 \t42 \n", 'app' => 'TILL'])->assertOk();

        $this->assertNotNull($device->fresh()->serial_verified_at);
    }

    public function test_a_different_device_is_refused_and_the_code_and_the_live_token_survive(): void
    {
        $device = $this->liveTill();

        $this->activate('fresh-code', ['serial' => 'G7-SN-9999', 'app' => 'till', 'manufacturer' => 'ZCS', 'model' => 'G7'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'activation_device_mismatch')
            ->assertJsonPath('errors.0.code', 'activation_device_mismatch')
            ->assertJsonPath('data', null)
            ->assertJson(fn ($json) => $json->whereType('message', 'string')->etc());

        $this->assertCodeUnused('fresh-code');
        $this->assertLiveTokenStillWorks($device);
        $this->assertNull($device->fresh()->serial_verified_at);
        $attempt = DB::table('pos_device_activation_attempts')->sole();
        $this->assertSame((int) $device->id, (int) $attempt->device_id);
        $this->assertSame('refused', $attempt->outcome);
        $this->assertSame('activation_device_mismatch', $attempt->reason);
        $this->assertSame('enforce', $attempt->binding_mode);
        // The full serial is kept so an admin can correct a mis-typed record.
        $this->assertSame('G7-SN-9999', $attempt->reported_serial);
        $this->assertSame('******9999', $attempt->reported_serial_masked);
        $this->assertSame(hash('sha256', 'G7-SN-9999'), $attempt->reported_serial_hash);
        $this->assertSame(['till', 'ZCS', 'G7', '198.51.100.7'], [$attempt->app, $attempt->manufacturer, $attempt->model, $attempt->ip_address]);
        $this->assertNotNull($attempt->created_at);

        // The same code then still works on the device it was made for.
        $this->activate('fresh-code', ['serial' => 'T3-SN-0042', 'app' => 'till'])->assertOk();
    }

    public function test_a_missing_serial_is_refused_under_enforce_without_consuming_the_code(): void
    {
        $device = $this->liveTill();

        foreach ([[], ['serial' => '   '], ['serial' => null]] as $claim) {
            $this->activate('fresh-code', $claim + ['app' => 'till'])
                ->assertStatus(422)->assertJsonPath('code', 'activation_serial_missing');
        }

        $this->assertCodeUnused('fresh-code');
        $this->assertLiveTokenStillWorks($device);
        $this->assertSame(3, DB::table('pos_device_activation_attempts')->where('reason', 'activation_serial_missing')->count());
    }

    public function test_enforce_is_the_default_and_an_unknown_mode_fails_closed(): void
    {
        $this->liveTill();
        config(['pos.device_serial_binding' => null]);
        $this->activate('fresh-code')->assertStatus(422)->assertJsonPath('code', 'activation_serial_missing');
        config(['pos.device_serial_binding' => 'sometimes']);
        $this->activate('fresh-code')->assertStatus(422)->assertJsonPath('code', 'activation_serial_missing');
        $this->assertCodeUnused('fresh-code');
    }

    /** @return array<string, array{string}> */
    public static function wrongApps(): array
    {
        return ['station app on a till' => ['station'], 'handheld app on a till' => ['handheld'],
            'tablet app on a till' => ['customer_tablet'], 'unknown app name' => ['kiosk']];
    }

    #[DataProvider('wrongApps')]
    public function test_the_app_must_match_the_device_type(string $app): void
    {
        $device = $this->liveTill();

        $this->activate('fresh-code', ['serial' => 'T3-SN-0042', 'app' => $app])
            ->assertStatus(422)->assertJsonPath('code', 'activation_app_mismatch');

        $this->assertCodeUnused('fresh-code');
        $this->assertLiveTokenStillWorks($device);
        $this->assertSame('activation_app_mismatch', DB::table('pos_device_activation_attempts')->sole()->reason);
    }

    /** @return array<string, array{string, string}> */
    public static function rightApps(): array
    {
        return ['till' => ['till', 'fixed_pos'], 'handheld' => ['handheld', 'handheld'],
            'station' => ['station', 'payment_station'], 'customer tablet' => ['customer_tablet', 'customer_tablet']];
    }

    #[DataProvider('rightApps')]
    public function test_each_app_activates_its_own_device_type(string $app, string $type): void
    {
        $device = Device::factory()->create(['serial_number' => 'SN-'.$type, 'device_type' => $type]);
        DeviceActivationToken::factory()->for($device)->forPlaintext('code-'.$type)->create();

        $this->activate('code-'.$type, ['serial' => 'sn-'.$type, 'app' => $app])->assertOk();
    }

    public function test_report_mode_allows_a_mismatch_but_records_it(): void
    {
        config(['pos.device_serial_binding' => 'report']);
        $device = $this->liveTill();

        $this->activate('fresh-code', ['serial' => 'G7-SN-9999', 'app' => 'station'])->assertOk();

        $this->assertNull($device->fresh()->serial_verified_at);
        $this->assertEqualsCanonicalizing(
            ['activation_device_mismatch', 'activation_app_mismatch'],
            DB::table('pos_device_activation_attempts')->pluck('reason')->all(),
        );
        $this->assertSame(['reported'], DB::table('pos_device_activation_attempts')->distinct()->pluck('outcome')->all());
        $this->assertSame(['report'], DB::table('pos_device_activation_attempts')->distinct()->pluck('binding_mode')->all());
    }

    public function test_report_mode_allows_and_records_a_missing_serial_and_off_records_nothing(): void
    {
        config(['pos.device_serial_binding' => 'report']);
        $this->liveTill('report-code');
        $this->activate('report-code')->assertOk();
        $this->assertEqualsCanonicalizing(['activation_serial_missing', 'activation_app_missing'],
            DB::table('pos_device_activation_attempts')->pluck('reason')->all());

        config(['pos.device_serial_binding' => 'off']);
        $other = Device::factory()->create(['device_type' => 'handheld']);
        DeviceActivationToken::factory()->for($other)->forPlaintext('off-code')->create();
        $this->activate('off-code', ['serial' => 'NOT-THIS-ONE', 'app' => 'till'])->assertOk();
        $this->assertDatabaseCount('pos_device_activation_attempts', 2);
        $this->assertNull($other->fresh()->serial_verified_at);
    }

    public function test_a_used_code_cannot_be_replayed_even_by_the_right_device(): void
    {
        $this->liveTill();
        $claim = ['serial' => 'T3-SN-0042', 'app' => 'till'];
        $token = $this->activate('fresh-code', $claim)->assertOk()->json('data.device_token');

        $this->activate('fresh-code', $claim)->assertStatus(422)
            ->assertJsonPath('code', 'activation_failed')->assertJsonPath('errors.0.code', 'activation_failed');

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/device/heartbeat')->assertOk();
    }

    public function test_round_up_after_a_move_is_forwarded_with_the_new_assignments_settings(): void
    {
        // P1-9: admin replaced the round-up settings when it moved the device
        // (old merchant's 7/3 are gone); the re-activated device forwards the new ones.
        config(['services.charity.url' => 'http://charity.test', 'sync.stranded_sweep_after_id' => 0]);
        Http::fake(['*' => Http::response(['success' => true], 201)]);
        $device = Device::factory()->create(['serial_number' => 'G7-MOVED-1', 'device_type' => 'handheld',
            'company_id' => 100, 'branch_id' => 10, 'bank_id' => 5, 'terminal_id' => 'TID-NEW',
            'commission_profile_id' => 21, 'organization_id' => 31]);
        DeviceActivationToken::factory()->for($device)->forPlaintext('moved-code')->create();
        $token = $this->activate('moved-code', ['serial' => 'g7-moved-1', 'app' => 'handheld'])->assertOk()->json('data.device_token');
        $orderId = DB::table('pos_orders')->insertGetId(['uuid' => 'moved-order', 'company_id' => 100, 'branch_id' => 10,
            'order_type' => 'quick', 'status' => 'paid', 'source' => 'handheld', 'subtotal' => '4.800', 'discount_total' => 0,
            'tax_total' => 0, 'grand_total' => '4.800', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_payments')->insert(['uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card', 'amount' => '5.000',
            'status' => 'success', 'pending_reconciliation' => false, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'donation.record', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => 'moved-order', 'amount_baisas' => 200, 'receipt' => ['status' => 'success']],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $this->assertDatabaseHas('pos_roundup_donations', ['device_id' => $device->id, 'commission_profile_id' => 21, 'organization_id' => 31]);
        Http::assertSent(fn (Request $request): bool => $request['commission_profile_id'] === 21 && $request['organization_id'] === 31
            && $request['pos_device_id'] === $device->id);
        $this->assertNotNull(DB::table('pos_roundup_donations')->value('forwarded_at'));
    }

    public function test_the_legacy_pair_endpoint_is_locked_the_same_way(): void
    {
        $device = $this->liveTill();
        $device->forceFill(['kiosk_id' => 'KIOSK-P1'])->save();

        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/device/pair', ['kiosk_id' => 'KIOSK-P1', 'activation_token' => 'fresh-code', 'serial' => 'OTHER'])
            ->assertStatus(422)->assertJsonPath('code', 'activation_device_mismatch');
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/device/pair', ['kiosk_id' => 'KIOSK-P1', 'activation_token' => 'fresh-code'])
            ->assertStatus(422)->assertJsonPath('code', 'activation_serial_missing');

        $this->assertCodeUnused('fresh-code');
        $this->assertLiveTokenStillWorks($device);
    }

    public function test_a_missing_app_is_refused_under_enforce_and_recorded_under_report(): void
    {
        $device = $this->liveTill();

        $this->activate('fresh-code', ['serial' => 'T3-SN-0042'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'activation_app_missing')
            ->assertJsonPath('errors.0.code', 'activation_app_missing')
            ->assertJsonPath('data', null);
        $this->assertCodeUnused('fresh-code');
        $this->assertLiveTokenStillWorks($device);
        $this->assertSame('activation_app_missing', DB::table('pos_device_activation_attempts')->sole()->reason);

        config(['pos.device_serial_binding' => 'report']);
        $this->activate('fresh-code', ['serial' => 'T3-SN-0042'])->assertOk();
        $this->assertSame(['refused', 'reported'], DB::table('pos_device_activation_attempts')
            ->where('reason', 'activation_app_missing')->orderBy('id')->pluck('outcome')->all());
    }

    public function test_a_code_refused_five_times_is_revoked_audited_and_listed(): void
    {
        $device = $this->liveTill();
        $wrong = ['serial' => 'G7-SN-9999', 'app' => 'till', 'manufacturer' => 'ZCS', 'model' => 'G7'];

        foreach (range(1, 4) as $attempt) {
            $this->activate('fresh-code', $wrong)->assertStatus(422)->assertJsonPath('code', 'activation_device_mismatch');
        }
        $this->assertCodeUnused('fresh-code');

        $this->activate('fresh-code', $wrong)->assertStatus(422)->assertJsonPath('code', 'activation_device_mismatch');
        $token = DeviceActivationToken::query()->where('token_hash', DeviceActivationToken::hash('fresh-code'))->firstOrFail();
        $this->assertNotNull($token->revoked_at);
        $this->assertNull($token->used_at);
        $this->assertSame(5, DB::table('pos_device_activation_attempts')->where('outcome', 'refused')->count());
        $revocation = DB::table('pos_device_activation_attempts')->where('outcome', 'code_revoked')->sole();
        $this->assertSame(['too_many_refusals', (int) $token->id, (int) $device->id, 'G7-SN-9999'],
            [$revocation->reason, (int) $revocation->activation_token_id, (int) $revocation->device_id, $revocation->reported_serial]);
        $audit = DB::table('pos_audit_logs')->where('event', 'device.activation_token.revoked')->sole();
        $this->assertSame((int) $token->id, (int) $audit->auditable_id);
        $this->assertSame(['device_id' => (int) $device->id, 'reason' => 'too_many_refused_activations', 'refused_attempts' => 5],
            json_decode((string) $audit->metadata, true));

        // The code is dead even for the right device; the live token is untouched.
        $this->activate('fresh-code', ['serial' => 'T3-SN-0042', 'app' => 'till'])
            ->assertStatus(422)->assertJsonPath('code', 'activation_failed');
        $this->assertLiveTokenStillWorks($device);
        $this->assertSame(1, DB::table('pos_audit_logs')->where('event', 'device.activation_token.revoked')->count());
    }
}
