<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\CloseTableSessionForOrderAction;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class JoinedSeatingClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
    }

    #[DataProvider('paymentOrVoid')]
    public function test_existing_pay_and_void_close_primary_and_joined_but_never_aliases(string $eventType, string $reason): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$device, $order, $primary, $joined, $alias] = $this->fixture();
        $aliasBefore = $alias->getRawOriginal();
        $billingAt = now()->subMinute();
        $primary->update(['status' => 'billing', 'billing_at' => $billingAt]);
        $joined[0]->update(['status' => 'billing', 'billing_at' => $billingAt]);
        $payload = ['order_uuid' => (string) $order->uuid];
        if ($eventType === 'order.pay') {
            $payload += ['paid_at' => now()->toIso8601String(), 'payments' => [
                ['method' => 'cash', 'amount_baisas' => 1000, 'status' => 'success'],
            ]];
        } else {
            $payload += ['voided_at' => now()->toIso8601String(), 'reason' => 'Joined seating cancellation'];
        }
        app('auth')->forgetGuards();
        $response = $this->withToken((string) $device->device_token)->postJson('/api/v1/device/sync/push', [
            'events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => $eventType,
                'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
            ]],
        ])->assertOk();
        $this->assertSame('processed', $response->json('data.results.0.status'), $response->getContent());
        $this->assertSame($eventType === 'order.pay' ? 'paid' : 'void', $order->fresh()->status);
        foreach ([$primary, ...$joined] as $seating) {
            $seating->refresh();
            $this->assertSame('closed', $seating->status);
            $this->assertSame($reason, $seating->close_reason);
            $this->assertSame((int) $device->id, (int) $seating->closed_by_device_id);
            $this->assertTrue($primary->closed_at->equalTo($seating->closed_at));
        }
        $this->assertTrue($primary->billing_at->equalTo($billingAt));
        $this->assertTrue($joined[0]->billing_at->equalTo($billingAt));
        $this->assertSame($aliasBefore, $alias->fresh()->getRawOriginal());
        $events = TableSessionEvent::query()->orderBy('id')->get();
        $this->assertSame(['closed', 'closed', 'closed'], $events->pluck('event_type')->all());
        $this->assertSame([(int) $primary->id, (int) $joined[0]->id, (int) $joined[1]->id], $events->pluck('table_session_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([$reason, $reason, $reason], $events->pluck('payload.close_reason')->all());
        $this->assertSame([(string) $order->uuid, (string) $order->uuid, (string) $order->uuid], $events->pluck('payload.order_uuid')->all());
    }

    public static function paymentOrVoid(): array
    {
        return [['order.pay', 'paid'], ['order.void', 'voided']];
    }

    public function test_close_count_and_generation_guards_are_exact_and_replay_writes_no_events(): void
    {
        [$device, $order, $primary, $joined, $alias] = $this->fixture();
        $joined[1]->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'cleared']);
        $terminalBefore = $joined[1]->refresh()->getRawOriginal();
        $aliasBefore = $alias->getRawOriginal();
        $count = DB::transaction(fn (): int => app(CloseTableSessionForOrderAction::class)->handle(
            $order, now(), 'paid', (int) $device->id,
        ));
        $this->assertSame(2, $count);
        $this->assertSame($terminalBefore, $joined[1]->fresh()->getRawOriginal());
        $this->assertSame($aliasBefore, $alias->fresh()->getRawOriginal());
        $this->assertSame([(int) $primary->id, (int) $joined[0]->id], TableSessionEvent::query()->orderBy('id')->pluck('table_session_id')->map(fn ($id): int => (int) $id)->all());
        $eventsBefore = TableSessionEvent::query()->orderBy('id')->get()->toArray();
        $this->assertSame(0, DB::transaction(fn (): int => app(CloseTableSessionForOrderAction::class)->handle(
            $order, now()->addHour(), 'voided', null,
        )));
        $this->assertSame($eventsBefore, TableSessionEvent::query()->orderBy('id')->get()->toArray());
    }

    #[DataProvider('unpaidCoverage')]
    public function test_clear_refuses_every_unpaid_source_on_primary_and_joined_tables(string $source, string $status, bool $viaJoined): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$device, $order, $primary, $joined] = $this->fixture();
        $order->update(['source' => $source, 'status' => $status]);
        $before = [Order::query()->get()->toArray(), TableSession::query()->orderBy('id')->get()->toArray()];
        $target = $viaJoined ? $joined[0] : $primary;
        $this->withToken($device->device_token)->postJson('/api/v1/device/qr/clear-table', ['table_id' => $target->table_id])
            ->assertStatus(409)->assertJsonPath('errors.0.code', $status === 'awaiting_payment' ? 'qr_table_payment_pending' : 'qr_table_unpaid_order');
        $this->assertSame($before, [Order::query()->get()->toArray(), TableSession::query()->orderBy('id')->get()->toArray()]);
        $this->assertDatabaseCount('pos_table_session_events', 0);
    }

    public static function unpaidCoverage(): array
    {
        $cases = [];
        foreach (['main_pos', 'handheld', 'qr_web'] as $source) {
            foreach (['open', 'held', 'awaiting_payment', 'kitchen'] as $status) {
                foreach ([false, true] as $joined) {
                    $cases[] = [$source, $status, $joined];
                }
            }
        }

        return $cases;
    }

    public function test_terminal_joined_clear_closes_only_physical_seating_and_rejects_bill_wide_pending_rounds(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$device, $order, $primary, $joined, $alias] = $this->fixture();
        $order->update(['status' => 'paid']);
        $round = QrOrderRound::query()->create([
            'qr_session_id' => null, 'table_session_id' => $primary->id, 'order_id' => $order->id,
            'round_no' => 1, 'status' => 'pending_confirmation', 'client_request_id' => (string) Str::uuid(),
            'priced_lines' => [['line_total_baisas' => 1000]], 'confirm_payload' => ['preserve' => true],
            'subtotal_baisas' => 1000, 'tax_baisas' => 0, 'total_baisas' => 1000, 'submitted_at' => now(),
        ]);
        $before = [$primary->getRawOriginal(), $joined[1]->getRawOriginal(), $alias->getRawOriginal(), $order->fresh()->getRawOriginal()];
        $this->withToken($device->device_token)->postJson('/api/v1/device/qr/clear-table', ['table_id' => $joined[0]->table_id])
            ->assertOk()->assertJsonPath('data.status', 'cleared');
        $this->assertSame('closed', $joined[0]->fresh()->status);
        $this->assertSame('cleared', $joined[0]->fresh()->close_reason);
        $this->assertSame('rejected', $round->fresh()->status);
        $this->assertNull($round->fresh()->confirm_payload);
        $this->assertSame($before, [$primary->fresh()->getRawOriginal(), $joined[1]->fresh()->getRawOriginal(), $alias->fresh()->getRawOriginal(), $order->fresh()->getRawOriginal()]);
        $this->assertSame([(int) $joined[0]->id], TableSessionEvent::query()->pluck('table_session_id')->map(fn ($id): int => (int) $id)->all());
        $closedBefore = $joined[0]->fresh()->getRawOriginal();
        $this->travel(1)->hours();
        $this->withToken($device->device_token)->postJson('/api/v1/device/qr/clear-table', ['table_id' => $joined[0]->table_id])->assertOk();
        $this->assertSame($closedBefore, $joined[0]->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pos_table_session_events', 1);
    }

    /** @return array{Device, Order, TableSession, list<TableSession>, TableSession} */
    private function fixture(): array
    {
        Branch::query()->create([
            'id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Joined branch', 'status' => 'active',
        ]);
        $device = Device::factory()->paired()->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => 'fixed_pos',
        ]);
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Joined floor', 'display_order' => 1, 'status' => 'active',
        ]);
        $tables = [];
        foreach (range(1, 3) as $index) {
            $tables[] = Table::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => $floor->id,
                'label' => 'JOIN-'.$index, 'seats' => 4, 'shape' => 'square',
                'status' => 'active', 'display_order' => $index,
            ]);
        }
        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $device->id, 'table_id' => $tables[0]->id,
            'order_type' => 'dine_in', 'status' => 'open', 'source' => 'main_pos',
            'subtotal' => '1.000', 'discount_total' => '0.000', 'comp_total' => '0.000',
            'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now(),
        ]);
        $key = (string) Str::uuid();
        $base = [
            'company_id' => 100, 'branch_id' => 10, 'status' => 'open', 'origin' => 'staff_till',
            'opened_by_device_id' => $device->id, 'order_id' => $order->id,
            'opened_at' => now(), 'expires_at' => now()->addHours(6),
        ];
        $primary = TableSession::query()->create($base + [
            'uuid' => (string) Str::uuid(), 'table_id' => $tables[0]->id,
            'client_request_id' => $key, 'temp_reference' => 'T-0905-001',
        ]);
        $order->update(['table_session_id' => $primary->id, 'temp_reference' => $primary->temp_reference]);
        $joined = [];
        foreach ([$tables[1], $tables[2]] as $table) {
            $joined[] = TableSession::query()->create($base + [
                'uuid' => (string) Str::uuid(), 'table_id' => $table->id,
                'client_request_id' => $key.'#'.$table->id, 'merged_into_id' => $primary->id,
            ]);
            DB::table('pos_order_tables')->insert(['order_id' => $order->id, 'table_id' => $table->id]);
        }
        $alias = TableSession::query()->create(array_replace($base, [
            'uuid' => (string) Str::uuid(), 'table_id' => $tables[0]->id,
            'client_request_id' => (string) Str::uuid(), 'merged_into_id' => $primary->id,
            'status' => 'merged', 'order_id' => null, 'closed_at' => now(), 'close_reason' => 'attached',
        ]));

        return [$device, $order->refresh(), $primary->refresh(), array_map(fn (TableSession $seating): TableSession => $seating->refresh(), $joined), $alias->refresh()];
    }
}
