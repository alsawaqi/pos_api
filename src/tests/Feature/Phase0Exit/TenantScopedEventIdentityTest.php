<?php

declare(strict_types=1);

namespace Tests\Feature\Phase0Exit;

use App\Models\Device;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 0 exit — W-A5 / EXIT-15.
 *
 * "Tenant-scoped client-ID collision: every event and financial effect
 * remains scoped to the authenticated tenant/device."
 *
 * (a) Idempotency is keyed on (device_id, client_event_id) — so the SAME
 *     company reusing one client_event_id across two DIFFERENT devices is
 *     two independent ledger rows with two independent effects, never a
 *     cross-device dedupe swallow and never a leaked ACK.
 *
 * (b) Order identity: CreateOrderHandler locks by order uuid GLOBALLY
 *     (Order::query()->where('uuid', …)->lockForUpdate(), then re-checks
 *     company/branch against the authenticated device — CreateOrderHandler
 *     ~line 78). A tenant-B order.create colliding with tenant A's existing
 *     order uuid must FAIL without adopting, mutating or leaking tenant A's
 *     row.
 */
class TenantScopedEventIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Cashier 7 for tenant A (company 100 / branch 10); cashier 88 for
        // tenant B (company 200 / branch 20).
        $this->seedPosStaff([7]);
        $this->seedPosStaff([88], companyId: 200, branchId: 20);
    }

    private function seedCatalogues(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte', 'base_price' => 3.000, 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'Foreign Karak', 'base_price' => 5.000, 'status' => 'active'] + $t,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        // Each real HTTP request resolves its own bearer token; drop the
        // cached device guard between requests from different devices.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseEvent(string $clientEventId, int $amountBaisas, int $staffId): array
    {
        return [
            'client_event_id' => $clientEventId,
            'event_type' => 'expense.log',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'category' => 'utilities',
                'amount_baisas' => $amountBaisas,
                'note' => 'shared-id probe',
                'staff_id' => $staffId,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createOrderEvent(string $orderUuid, int $productId, int $staffId, int $amountBaisas): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $orderUuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'staff_id' => $staffId,
                'opened_at' => now()->toIso8601String(),
                'subtotal_baisas' => $amountBaisas,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => $amountBaisas,
                'lines' => [['product_id' => $productId, 'qty' => 1, 'unit_price_baisas' => $amountBaisas, 'line_discount_baisas' => 0, 'line_total_baisas' => $amountBaisas]],
            ]],
        ];
    }

    public function test_same_company_different_device_reuse_of_one_client_event_id_settles_independently(): void
    {
        Device::factory()->paired('mdev_p0_a')->create(['company_id' => 100, 'branch_id' => 10]);
        Device::factory()->paired('mdev_p0_b')->create(['company_id' => 100, 'branch_id' => 10]);
        $sharedId = (string) Str::uuid();

        // Device A uses the id first.
        $a = $this->push('mdev_p0_a', [$this->expenseEvent($sharedId, 5000, 7)])
            ->assertOk()
            ->json('data.results.0');
        $this->assertFalse($a['duplicate']);
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $a['status']);

        // Device B — SAME company, DIFFERENT device — reusing the same id is
        // NOT a duplicate: it gets its own ledger row and its own effect.
        $b = $this->push('mdev_p0_b', [$this->expenseEvent($sharedId, 7000, 7)])
            ->assertOk()
            ->json('data.results.0');
        $this->assertFalse($b['duplicate']);
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $b['status']);

        // Two independent ledger rows…
        $this->assertDatabaseCount('pos_sync_events', 2);
        $this->assertNotSame((int) $a['event_id'], (int) $b['event_id']);

        // …and two independent financial effects, each its own amount.
        $this->assertDatabaseCount('pos_expenses', 2);
        $amounts = Expense::query()->orderBy('id')->pluck('amount')->map(fn ($v): float => (float) $v)->all();
        $this->assertEqualsWithDelta([5.000, 7.000], $amounts, 1e-9);
        $this->assertNotSame(
            (int) $a['result']['expense_id'],
            (int) $b['result']['expense_id'],
        );

        // Each device replaying ITS OWN copy still dedupes to ITS OWN ACK.
        $replayA = $this->push('mdev_p0_a', [$this->expenseEvent($sharedId, 5000, 7)])
            ->assertOk()
            ->json('data.results.0');
        $this->assertTrue($replayA['duplicate']);
        $this->assertSame((int) $a['event_id'], (int) $replayA['event_id']);
        $this->assertSame((int) $a['result']['expense_id'], (int) $replayA['result']['expense_id']);
        $this->assertDatabaseCount('pos_sync_events', 2);
        $this->assertDatabaseCount('pos_expenses', 2);
    }

    public function test_cross_tenant_order_uuid_collision_never_adopts_or_mutates_the_foreign_order(): void
    {
        $this->seedCatalogues();
        Device::factory()->paired('mdev_p0_a')->create(['company_id' => 100, 'branch_id' => 10]);
        Device::factory()->paired('mdev_p0_x')->create(['company_id' => 200, 'branch_id' => 20]);
        $collidingUuid = (string) Str::uuid();

        // Tenant A owns the uuid first.
        $this->push('mdev_p0_a', [$this->createOrderEvent($collidingUuid, 1, 7, 3000)])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        $original = Order::firstWhere('uuid', $collidingUuid);
        $originalItem = OrderItem::firstWhere('order_id', $original->id);

        // Tenant B pushes order.create with the SAME uuid (own product, own
        // staff, different money). The safe behavior pinned here: the event
        // FAILS — B neither adopts, reads, nor overwrites A's row.
        $event = $this->createOrderEvent($collidingUuid, 2, 88, 5000);
        $res = $this->push('mdev_p0_x', [$event])->assertOk();

        $this->assertSame(SyncEvent::STATUS_FAILED, $res->json('data.results.0.status'));
        $this->assertSame(
            'order uuid already exists outside the device tenant',
            $res->json('data.results.0.result.error'),
        );

        // Exactly one order carries the uuid, and it is STILL tenant A's,
        // byte-for-byte: same tenant, money, cashier, line and snapshot.
        $this->assertSame(1, Order::query()->where('uuid', $collidingUuid)->count());
        $surviving = Order::firstWhere('uuid', $collidingUuid);
        $this->assertSame((int) $original->id, (int) $surviving->id);
        $this->assertSame(100, (int) $surviving->company_id);
        $this->assertSame(10, (int) $surviving->branch_id);
        $this->assertSame('3.000', $surviving->grand_total);
        $this->assertSame(7, (int) $surviving->staff_id);
        $this->assertSame(Order::STATUS_OPEN, $surviving->status);
        $this->assertSame(0, Order::query()->where('company_id', 200)->count());

        $survivingItem = OrderItem::firstWhere('order_id', $surviving->id);
        $this->assertSame((int) $originalItem->id, (int) $survivingItem->id);
        $this->assertSame(1, (int) $survivingItem->product_id);
        $this->assertSame('Latte', $survivingItem->product_name_snapshot);
        $this->assertSame(1, OrderItem::query()->count());

        // B's rejection is durably parked in ITS OWN ledger row.
        $this->assertDatabaseHas('pos_sync_events', [
            'client_event_id' => $event['client_event_id'],
            'ack_status' => SyncEvent::STATUS_FAILED,
        ]);

        // A retry from tenant B stays deterministically rejected — the
        // collision can never be worn down into an adoption.
        $retry = $this->push('mdev_p0_x', [$event])->assertOk();
        $this->assertTrue($retry->json('data.results.0.duplicate'));
        $this->assertSame(SyncEvent::STATUS_FAILED, $retry->json('data.results.0.status'));
        $this->assertSame(
            'order uuid already exists outside the device tenant',
            $retry->json('data.results.0.result.error'),
        );
        $this->assertSame(1, Order::query()->where('uuid', $collidingUuid)->count());
        $this->assertSame(100, (int) Order::firstWhere('uuid', $collidingUuid)->company_id);
    }
}
