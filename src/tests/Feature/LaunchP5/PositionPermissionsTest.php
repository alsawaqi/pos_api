<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Support\Staff\PositionPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 A2 — the tick list resolver and the device config.
 *
 * The resolver is tested against the shared fixture
 * (tests/Fixtures/position_permissions_defaults.json, a byte copy of
 * D:\launch-work\p5\shared\position_permissions_defaults.json; the runtime
 * copy in resources/staff must be byte-identical). Missing positions,
 * actions and limits resolve to the defaults; a company without a tick list
 * resolves like the LAUNCH-P5 data migration (defaults + three old lists).
 */
class PositionPermissionsTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/position_permissions_defaults.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function setting(string $key, mixed $value, int $company = 100): void
    {
        DB::table('pos_company_settings')->insert(['company_id' => $company, 'key' => $key, 'value' => json_encode($value),
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_runtime_defaults_are_a_byte_copy_of_the_shared_fixture(): void
    {
        $this->assertSame(
            hash_file('sha256', base_path('tests/Fixtures/position_permissions_defaults.json')),
            hash_file('sha256', resource_path('staff/position_permissions_defaults.json')),
        );
    }

    public function test_a_company_with_nothing_set_resolves_to_the_fixture_defaults(): void
    {
        $fixture = $this->fixture();
        $matrix = app(PositionPermissions::class)->forCompany(100);

        $this->assertSame($fixture['positions'], array_keys($matrix));
        foreach ($fixture['positions'] as $position) {
            $this->assertSame($fixture['actions'], array_keys($matrix[$position]['actions']), $position);
            $this->assertSame($fixture['defaults'][$position]['actions'], $matrix[$position]['actions'], $position);
            $this->assertSame($fixture['defaults'][$position]['discount_max_percent'], $matrix[$position]['discount_max_percent'], $position);
        }
    }

    public function test_a_stored_tick_list_wins_and_every_missing_or_malformed_value_takes_the_default(): void
    {
        $this->setting('position_permissions', [
            'cashier' => ['actions' => ['comp' => true, 'discount.manual' => 'yes', 'no.such.action' => true], 'discount_max_percent' => 15],
            'waiter' => ['discount_max_percent' => 140],
            'kitchen' => ['actions' => ['kitchen.screen' => false]],
            'owner' => ['actions' => ['comp' => true]],
        ]);
        // An old list is ignored once a tick list exists.
        $this->setting('manager_approval_positions', ['cashier']);

        $resolver = app(PositionPermissions::class);
        $matrix = $resolver->forCompany(100);
        $defaults = $this->fixture()['defaults'];

        $this->assertTrue($matrix['cashier']['actions']['comp']);
        $this->assertSame($defaults['cashier']['actions']['discount.manual'], $matrix['cashier']['actions']['discount.manual']);
        $this->assertArrayNotHasKey('no.such.action', $matrix['cashier']['actions']);
        $this->assertSame(15, $matrix['cashier']['discount_max_percent']);
        $this->assertSame(10, $matrix['waiter']['discount_max_percent'], 'out of range keeps the default');
        $this->assertTrue($matrix['kitchen']['actions']['kitchen.screen'], 'the kitchen position always opens the kitchen screen');
        $this->assertArrayNotHasKey('owner', $matrix);
        $this->assertSame(['manager'], $resolver->positionsWith(100, 'approvals.give'));
        $this->assertTrue($resolver->allows(100, 'cashier', 'comp'));
        $this->assertFalse($resolver->allows(100, 'cashier', 'gift'));
        $this->assertFalse($resolver->allows(100, null, 'receipt.reprint'));
    }

    public function test_without_a_tick_list_the_old_lists_map_like_the_data_migration(): void
    {
        $this->setting('manager_approval_positions', ['supervisor', 'manager']);
        $this->setting('reports_positions', ['supervisor']);
        $this->setting('kitchen_positions', ['waiter']);
        $this->setting('order_cancel_positions', ['cashier']);

        $resolver = app(PositionPermissions::class);
        $this->assertSame(['supervisor', 'manager'], $resolver->positionsWith(100, 'approvals.give'));
        $this->assertSame(['supervisor'], $resolver->positionsWith(100, 'reports.view'));
        $this->assertSame(['waiter', 'kitchen'], $resolver->positionsWith(100, 'kitchen.screen'));
        // order_cancel_positions is not mapped.
        $this->assertSame(['manager'], $resolver->positionsWith(100, 'order.void_paid'));
        // Another company keeps the defaults.
        $this->assertSame(['manager'], $resolver->positionsWith(200, 'approvals.give'));
        $this->assertSame(['kitchen', 'manager'], $resolver->positionsWith(200, 'kitchen.screen'));
    }

    public function test_the_config_carries_the_resolved_tick_list_the_reminder_and_old_lists_kept_in_sync(): void
    {
        $this->p5Device('mdev_p5cfg');
        $this->setting('position_permissions', [
            'supervisor' => ['actions' => ['approvals.give' => true, 'reports.view' => true]],
            'manager' => ['actions' => ['kitchen.screen' => false]],
        ]);
        $this->setting('order_cancel_positions', ['supervisor', 'manager']);
        DB::table('pos_branches')->insert(['id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_branch_settings')->insert(['company_id' => 100, 'branch_id' => 10, 'key' => 'shift_end_reminder_at',
            'value' => json_encode('22:30'), 'created_at' => now(), 'updated_at' => now()]);

        foreach (['/api/v1/device/config', '/api/v1/device/config/delta?since='.urlencode(now()->subMinute()->toIso8601String())] as $url) {
            $settings = $this->withToken('mdev_p5cfg')->getJson($url)->assertOk()->json('data.settings');

            $this->assertSame(array_keys($this->fixture()['defaults']), array_keys($settings['position_permissions']));
            $this->assertTrue($settings['position_permissions']['supervisor']['actions']['approvals.give']);
            $this->assertSame(25, $settings['position_permissions']['supervisor']['discount_max_percent']);
            $this->assertSame('22:30', $settings['shift_end_reminder_at']);
            // The old keys, for old app builds, follow the tick list.
            $this->assertSame(['supervisor', 'manager'], $settings['manager_approval_positions']);
            $this->assertSame(['supervisor', 'manager'], $settings['reports_positions']);
            $this->assertSame(['kitchen'], $settings['kitchen_positions']);
            $this->assertSame(['supervisor', 'manager'], $settings['order_cancel_positions']);
        }
    }

    public function test_an_unset_or_malformed_reminder_is_off(): void
    {
        $this->p5Device('mdev_p5rem');
        $this->assertNull($this->withToken('mdev_p5rem')->getJson('/api/v1/device/config')->json('data.settings.shift_end_reminder_at'));

        DB::table('pos_branches')->insert(['id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_branch_settings')->insert(['company_id' => 100, 'branch_id' => 10, 'key' => 'shift_end_reminder_at',
            'value' => json_encode('25:00'), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull($this->withToken('mdev_p5rem')->getJson('/api/v1/device/config')->json('data.settings.shift_end_reminder_at'));
    }
}
