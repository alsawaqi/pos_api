<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Models\Device;
use App\Models\Product;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP5Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 A3 — the ONLINE gated actions are refused unless authorized:
 *  - table cancel_line / cancel_bill / adjust take the `authorization` block
 *    (proof subject = the request's seating_key, ref = the block's ref) and
 *    refuse with 403 approval_required / approval_invalid; an old build's
 *    free-text authorized_by keeps working (a `legacy` row);
 *  - POST /device/products/{id}/sold-out takes the block (proof subject = the
 *    product uuid) and still accepts an old build's approver_staff_id;
 *  - the six PIN-checked actions write a `verified` / `online` row.
 */
class OnlineAuthorizationTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
        $this->p5Staff(9, 'supervisor', '900009');
    }

    private function postAs(Device $device, string $path, array $payload): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->plainTextToken)->postJson('/api/v1/device/'.$path, $payload);
    }

    /** A live table with a sent round of 3 × product. @return array{0: Device, 1: TableSession, 2: Product, 3: array<string, mixed>} */
    private function table(): array
    {
        $device = $this->seatingDevice();
        $seat = $this->seatingRow($this->seatingTable('P5 table'), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $prefix = ['table_id' => (int) $seat->table_id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false, 'staff_id' => 7];
        $this->postAs($device, 'tables/'.$seat->uuid.'/round', $prefix + [
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $product->id, 'qty' => 3]],
        ])->assertOk()->assertJsonPath('data.outcome', 'appended');

        return [$device, $seat->refresh(), $product, $prefix];
    }

    public function test_a_p5_sent_line_cancel_needs_its_block_and_records_the_approver(): void
    {
        [$device, $seat, $product, $prefix] = $this->table();
        $cancel = $prefix + ['auth_v' => 1, 'client_request_id' => (string) Str::uuid(), 'product_id' => (int) $product->id,
            'qty' => 1, 'prepared' => false, 'reason' => 'Wrong item', 'cancelled_at' => now()->toIso8601String()];

        // No block → 403 approval_required, nothing changes.
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $cancel)
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_required');
        // A cashier's own tick does not cover it (table.cancel_line: supervisor, manager).
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $cancel + ['authorization' => $this->p5Position('table.cancel_line', 7)])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_required');
        // A bad proof → 403 approval_invalid.
        $bad = $this->p5Approval($device, 'table.cancel_line', 8, 7, $seat->client_request_id);
        $bad['proof'] = str_repeat('0', 64);
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $cancel + ['authorization' => $bad])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_invalid');
        $this->assertSame(0, DB::table('pos_approvals')->count(), 'a refused online action writes nothing');

        $block = $this->p5Approval($device, 'table.cancel_line', 8, 7, $seat->client_request_id);
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $cancel + ['authorization' => $block])
            ->assertOk()->assertJsonPath('data.cancelled_qty', 1);
        $event = TableSessionEvent::query()->where('payload->client_request_id', $cancel['client_request_id'])->sole();
        $this->assertSame(8, $event->payload['approved_by_staff_id']);
        $row = DB::table('pos_approvals')->sole();
        $this->assertSame(['table.cancel_line', 'verified', 'table_session', $seat->client_request_id, $cancel['client_request_id']],
            [$row->action, $row->result, $row->subject_type, $row->subject_uuid, $row->client_event_id]);

        // A supervisor's own tick covers a sent-line cancel.
        $own = array_replace($cancel, ['client_request_id' => (string) Str::uuid(), 'staff_id' => 9,
            'authorization' => $this->p5Position('table.cancel_line', 9)]);
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $own)->assertOk()->assertJsonPath('data.cancelled_qty', 1);
    }

    public function test_a_p5_bill_cancel_needs_its_block_and_an_old_build_keeps_the_text(): void
    {
        [$device, $seat, $product, $prefix] = $this->table();
        $bill = $prefix + ['client_request_id' => (string) Str::uuid(), 'reason' => 'Left', 'cancelled_at' => now()->toIso8601String(),
            'lines' => [['client_request_id' => (string) Str::uuid(), 'product_id' => (int) $product->id, 'qty' => 3, 'prepared' => false]]];

        // An old build without the text is refused as today (422 validation).
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $bill)->assertStatus(422);
        // A P5 build without a block: 403 approval_required.
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $bill + ['auth_v' => 1])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_required');
        // The supervisor tick does not include cancelling a whole bill.
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', array_replace($bill, ['staff_id' => 9])
            + ['auth_v' => 1, 'authorization' => $this->p5Position('table.cancel_bill', 9)])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_required');

        $block = $this->p5Approval($device, 'table.cancel_bill', 8, 7, $seat->client_request_id);
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $bill + ['auth_v' => 1, 'authorization' => $block])
            ->assertOk()->assertJsonPath('data.status', 'void');
        $event = TableSessionEvent::query()->where('event_type', 'bill_cancelled')->sole();
        $this->assertSame(8, $event->payload['approved_by_staff_id']);
        $this->assertNull($event->payload['authorized_by']);
        $this->assertSame('verified', DB::table('pos_approvals')->where('action', 'table.cancel_bill')->value('result'));
    }

    public function test_an_old_build_bill_cancel_with_the_text_still_works_and_is_recorded_as_legacy(): void
    {
        [$device, $seat, $product, $prefix] = $this->table();
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $prefix + ['client_request_id' => (string) Str::uuid(),
            'reason' => 'Left', 'authorized_by' => 'Manager', 'cancelled_at' => now()->toIso8601String(),
            'lines' => [['client_request_id' => (string) Str::uuid(), 'product_id' => (int) $product->id, 'qty' => 3, 'prepared' => false]]])
            ->assertOk()->assertJsonPath('data.status', 'void');
        $this->assertSame(['table.cancel_bill', 'legacy'], [DB::table('pos_approvals')->value('action'), DB::table('pos_approvals')->value('result')]);
    }

    public function test_a_p5_table_discount_above_the_maximum_needs_an_approver_and_within_it_needs_none(): void
    {
        [$device, $seat, , $prefix] = $this->table();
        $adjust = fn (int $bp): array => $prefix + ['auth_v' => 1, 'client_request_id' => (string) Str::uuid(),
            'adjustment' => ['kind' => 'discount', 'mode' => 'percent', 'percent_bp' => $bp, 'label' => 'Manual']];

        // 5 % is inside the cashier's 10 %: no block needed.
        $this->postAs($device, 'tables/'.$seat->uuid.'/adjust', $adjust(500))->assertOk()->assertJsonPath('data.outcome', 'adjusted');
        $this->assertSame(0, DB::table('pos_approvals')->count());
        // 50 % is above it.
        $over = $adjust(5000);
        $this->postAs($device, 'tables/'.$seat->uuid.'/adjust', $over)->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_required');
        $over['authorization'] = $this->p5Approval($device, 'discount.manual', 8, 7, $seat->client_request_id, 1500);
        $this->postAs($device, 'tables/'.$seat->uuid.'/adjust', $over)->assertOk()->assertJsonPath('data.outcome', 'adjusted');
        $this->assertSame('verified', DB::table('pos_approvals')->value('result'));
    }

    public function test_sold_out_takes_the_block_and_an_old_build_keeps_its_approver_id(): void
    {
        $device = $this->p5Device('mdev_so');
        $uuid = (string) Str::uuid();
        DB::table('pos_products')->insert(['id' => 5, 'uuid' => $uuid, 'company_id' => 100, 'name' => 'Cake', 'base_price' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $url = '/api/v1/device/products/5/sold-out';

        // P5: a cashier needs an approval; the bare tick is refused.
        $this->withToken('mdev_so')->postJson($url, ['sold_out' => true, 'staff_id' => 7, 'auth_v' => 1,
            'authorization' => $this->p5Position('sold_out.toggle', 7)])->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_required');
        $this->withToken('mdev_so')->postJson($url, ['sold_out' => true, 'staff_id' => 7, 'auth_v' => 1,
            'authorization' => $this->p5Approval($device, 'sold_out.toggle', 8, 7, $uuid)])
            ->assertOk()->assertJsonPath('data.changed', true)->assertJsonPath('data.authorization.result', 'verified');
        $this->assertSame(8, (int) DB::table('pos_product_sold_out')->value('set_by_pos_staff_id'));
        // A supervisor switches it back on their own tick.
        $this->withToken('mdev_so')->postJson($url, ['sold_out' => false, 'staff_id' => 9, 'auth_v' => 1,
            'authorization' => $this->p5Position('sold_out.toggle', 9)])->assertOk()->assertJsonPath('data.authorization.result', 'position_ok');

        // An old build: approver_staff_id as today, recorded as legacy.
        $this->withToken('mdev_so')->postJson($url, ['sold_out' => true, 'staff_id' => 7, 'approver_staff_id' => 8])
            ->assertOk()->assertJsonPath('data.changed', true);
        $this->assertSame(['missing', 'verified', 'position_ok', 'legacy'], DB::table('pos_approvals')->orderBy('id')->pluck('result')->all());
    }

    public function test_a_pin_checked_action_records_a_verified_online_row_and_is_branch_limited(): void
    {
        $device = $this->p5Device('mdev_six');
        DB::table('pos_products')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Bread',
            'base_price' => 1, 'status' => 'active', 'stock_mode' => 'unit', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => 1, 'stock_qty' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->p5Staff(12, 'manager', '121212', overrides: ['branch_id' => 11]);
        $items = [['product_id' => 1, 'give_away_qty' => 1, 'give_away_comment' => 'Staff']];

        // A manager of another branch is not an approver here.
        $this->withToken('mdev_six')->postJson('/api/v1/device/disposition', ['items' => $items, 'pin' => '121212', 'staff_id' => 7])
            ->assertStatus(401)->assertJsonPath('errors.0.code', 'invalid_pin');
        $this->withToken('mdev_six')->postJson('/api/v1/device/disposition', ['items' => $items, 'pin' => '800008', 'staff_id' => 7])
            ->assertOk();
        $row = DB::table('pos_approvals')->sole();
        $this->assertSame(['disposition', 'verified', 'online', 'approval', 8, 7],
            [$row->action, $row->result, $row->method, $row->mode, (int) $row->approver_staff_id, (int) $row->actor_staff_id]);
        $this->assertSame($device->id, (int) $row->device_id);
    }
}
