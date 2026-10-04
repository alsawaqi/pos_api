<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (F10, review M8) — pos_approvals deduplicates ONLY
 * on (device, client_event_id, action, ref). Two people's actions on the
 * same subject with the same verdict are two rows; a repeat of the same
 * request is one.
 */
class ApprovalsDedupeTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(9, 'supervisor', '900009');
        $this->p5Staff(10, 'supervisor', '100010');
    }

    public function test_two_supervisors_cancelling_lines_of_one_table_are_two_rows(): void
    {
        // Review probe 5.
        $device = $this->seatingDevice();
        $seat = $this->seatingRow($this->seatingTable('P5 table'), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $prefix = ['table_id' => (int) $seat->table_id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false];
        $this->p5Online($device, 'POST', '/api/v1/device/tables/'.$seat->uuid.'/round', $prefix + ['staff_id' => 7, 'auth_v' => 1,
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $product->id, 'qty' => 3]]])->assertOk();

        foreach ([9, 10] as $supervisor) {
            $id = (string) Str::uuid();
            $this->p5Online($device, 'POST', '/api/v1/device/tables/'.$seat->uuid.'/cancel-line', $prefix + ['staff_id' => $supervisor,
                'auth_v' => 1, 'client_request_id' => $id, 'product_id' => (int) $product->id, 'qty' => 1, 'prepared' => false,
                'reason' => 'x', 'cancelled_at' => now()->toIso8601String(),
                'authorization' => $this->p5Position('table.cancel_line', $supervisor, $id)])
                ->assertOk()->assertJsonPath('data.cancelled_qty', 1);
        }

        $this->assertSame([[9, 'position_ok'], [10, 'position_ok']], DB::table('pos_approvals')->orderBy('id')
            ->get(['actor_staff_id', 'result'])->map(fn ($r): array => [(int) $r->actor_staff_id, $r->result])->all());
    }

    public function test_repeated_old_build_table_and_sold_out_actions_are_one_legacy_row_each(): void
    {
        $device = $this->seatingDevice();
        $seat = $this->seatingRow($this->seatingTable('Old table'), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $prefix = ['table_id' => (int) $seat->table_id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false, 'staff_id' => 7];
        $this->p5Online($device, 'POST', '/api/v1/device/tables/'.$seat->uuid.'/round', $prefix + [
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $product->id, 'qty' => 3]]])->assertOk();
        foreach ([1, 2] as $i) {
            $this->p5Online($device, 'POST', '/api/v1/device/tables/'.$seat->uuid.'/cancel-line', $prefix + [
                'client_request_id' => (string) Str::uuid(), 'product_id' => (int) $product->id, 'qty' => 1, 'prepared' => false,
                'reason' => 'x', 'cancelled_at' => now()->toIso8601String()])->assertOk()->assertJsonPath('data.cancelled_qty', 1);
        }
        foreach ([true, false] as $soldOut) {
            $this->p5Online($device, 'POST', '/api/v1/device/products/'.$product->id.'/sold-out',
                ['sold_out' => $soldOut, 'staff_id' => 9])->assertOk()->assertJsonPath('data.changed', true);
        }

        $this->assertSame([['table.cancel_line', 'legacy'], ['table.cancel_line', 'legacy'], ['sold_out.toggle', 'legacy'], ['sold_out.toggle', 'legacy']],
            DB::table('pos_approvals')->orderBy('id')->get(['action', 'result'])->map(fn ($r): array => [$r->action, $r->result])->all());
    }

    public function test_repeated_sold_out_toggles_are_one_row_each_and_a_repeated_request_is_one(): void
    {
        $device = $this->p5Device('mdev_so');
        DB::table('pos_products')->insert(['id' => 5, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cake',
            'base_price' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $toggle = fn (int $staff, bool $soldOut, string $id): array => ['sold_out' => $soldOut, 'staff_id' => $staff, 'auth_v' => 1,
            'client_request_id' => $id, 'authorization' => $this->p5Position('sold_out.toggle', $staff, $id)];
        $url = '/api/v1/device/products/5/sold-out';

        $ids = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->p5Online($device, 'POST', $url, $toggle(9, true, $ids[0]))->assertOk();
        $this->p5Online($device, 'POST', $url, $toggle(10, false, $ids[1]))->assertOk();
        $this->p5Online($device, 'POST', $url, $toggle(9, true, $ids[2]))->assertOk();
        // The same request again (a retry): its first verdict, no new row.
        $this->p5Online($device, 'POST', $url, $toggle(9, true, $ids[2]))->assertOk();

        $this->assertSame([[9, $ids[0]], [10, $ids[1]], [9, $ids[2]]], DB::table('pos_approvals')->orderBy('id')
            ->get(['actor_staff_id', 'client_event_id'])->map(fn ($r): array => [(int) $r->actor_staff_id, $r->client_event_id])->all());
    }
}
