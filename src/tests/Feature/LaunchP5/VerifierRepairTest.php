<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;
use Throwable;

/**
 * LAUNCH-P5 fix order 1 (L7) — verifier hygiene:
 *  - a successful login or manager-PIN check whose stored K does not match
 *    the typed PIN (the PIN was reset by something that left K behind, e.g.
 *    the old portal in the deploy window) recomputes K with the row's own
 *    salt and iterations, so the old PIN stops approving offline;
 *  - a failed verifier write never fails the login and is reported without
 *    K (the database error itself would print it with its bindings).
 */
class VerifierRepairTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p5Device('mdev_vr');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
        // The PIN is reset to 888888 by a writer that leaves K as it was.
        DB::table('pos_staff')->where('id', 8)->update(['pin_hash' => Hash::make('888888')]);
    }

    private function expectedKey(string $pin): string
    {
        $row = DB::table('pos_staff')->find(8);

        return bin2hex(hash_pbkdf2('sha256', $pin, (string) hex2bin($row->pin_offline_salt), (int) $row->pin_offline_iterations, 32, true));
    }

    public function test_a_login_with_the_new_pin_recomputes_a_stale_verifier(): void
    {
        $salt = DB::table('pos_staff')->where('id', 8)->value('pin_offline_salt');
        $this->assertSame($this->expectedKey('800008'), DB::table('pos_staff')->where('id', 8)->value('pin_offline_key'));

        $this->withToken('mdev_vr')->postJson('/api/v1/auth/pos/login', ['pin' => '888888'])->assertOk();

        $this->assertSame($this->expectedKey('888888'), DB::table('pos_staff')->where('id', 8)->value('pin_offline_key'));
        $this->assertSame([$salt, $this->p5Iterations], [DB::table('pos_staff')->where('id', 8)->value('pin_offline_salt'),
            (int) DB::table('pos_staff')->where('id', 8)->value('pin_offline_iterations')]);
    }

    public function test_a_manager_pin_check_with_the_new_pin_recomputes_it_and_serves_the_new_check(): void
    {
        $res = $this->withToken('mdev_vr')->postJson('/api/v1/device/auth/verify-manager-pin', ['pin' => '888888'])->assertOk();
        $key = $this->expectedKey('888888');
        $this->assertSame($key, DB::table('pos_staff')->where('id', 8)->value('pin_offline_key'));
        $this->assertSame(hash('sha256', 'mithqal-approver-check-v1'.hex2bin($key)), $res->json('staff.check'));

        // A matching verifier is left exactly as it is.
        DB::table('pos_staff')->where('id', 8)->update(['updated_at' => now()->subDay()]);
        $this->app['auth']->forgetGuards();
        $this->withToken('mdev_vr')->postJson('/api/v1/device/auth/verify-manager-pin', ['pin' => '888888'])->assertOk();
        $this->assertSame($key, DB::table('pos_staff')->where('id', 8)->value('pin_offline_key'));
    }

    public function test_a_failed_verifier_write_never_fails_the_login_and_is_reported_without_k(): void
    {
        Exceptions::fake();
        DB::statement("CREATE TRIGGER p5_block_verifier BEFORE UPDATE OF pin_offline_key ON pos_staff
            BEGIN SELECT RAISE(ABORT, 'verifier write blocked'); END");
        $stale = DB::table('pos_staff')->where('id', 8)->value('pin_offline_key');
        $key = $this->expectedKey('888888');

        $this->withToken('mdev_vr')->postJson('/api/v1/auth/pos/login', ['pin' => '888888'])->assertOk()->assertJsonPath('data.staff.id', 8);

        $this->assertSame($stale, DB::table('pos_staff')->where('id', 8)->value('pin_offline_key'));
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'offline approval verifier of staff member 8'));
        Exceptions::assertNotReported(fn (Throwable $e): bool => str_contains($e->getMessage(), $key)
            || str_contains($e->getMessage(), 'pin_offline_key'));
    }
}
