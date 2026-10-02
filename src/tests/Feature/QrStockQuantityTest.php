<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class QrStockQuantityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'synthetic-stock-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-10 12:00:00 UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        Branch::query()->create([
            'id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Stock regression', 'status' => 'active',
        ]);
        DB::table('pos_products')->insert([
            'id' => 105, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Butter Croissant', 'base_price' => '0.900',
            'stock_mode' => 'unit', 'status' => 'active',
            'show_on_customer_tablet' => true, 'is_internal' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => 105, 'is_available' => true,
            'stock_qty' => '23.000', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /*
     * LAUNCH-P2 P2-7 — sell, but warn. A QR quick order, a dine-in round and
     * a staff addition are never refused because of the branch shelf count
     * (QR-003's stock admission is gone): the order lands, the books may go
     * negative at payment, and the merchant sees it on the stock page. As
     * before, admission writes no stock — inventory moves once, at payment.
     */

    public static function oversizedCarts(): array
    {
        return ['one line' => [[25]], 'duplicate product lines' => [[12, 12]],
            'exact stock plus one' => [[23, 1]]];
    }

    #[DataProvider('oversizedCarts')]
    public function test_a_quick_checkout_above_the_shelf_count_is_accepted_without_any_stock_write(array $quantities): void
    {
        $this->submit($this->qrSession(), $quantities)->assertCreated();

        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertSame((float) array_sum($quantities), (float) DB::table('pos_order_items')->sum('qty'));
        $this->assertSame(23.0, (float) DB::table('pos_branch_product')->value('stock_qty'));
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
    }

    public function test_idempotent_replay_returns_the_same_order(): void
    {
        $session = $this->qrSession();
        $first = $this->submit($session, [12, 11], 'same')->assertCreated();
        $before = $this->rows();
        $this->submit($session, [12, 11], 'same')->assertCreated()->assertExactJson($first->json());
        $this->assertSame($before, $this->rows());
    }

    public function test_a_shelf_at_or_below_zero_still_sells_for_unit_and_cooked_products(): void
    {
        foreach (['unit', 'cooked'] as $mode) {
            DB::table('pos_products')->update(['stock_mode' => $mode]);
            foreach (['0.000', '-4.000'] as $balance) {
                DB::table('pos_branch_product')->update(['stock_qty' => $balance]);
                $this->submit($this->qrSession(), [2])->assertCreated();
            }
        }
        $this->assertDatabaseCount('pos_orders', 4);
    }

    public function test_unpaid_orders_do_not_hold_back_other_customers(): void
    {
        $this->submit($this->qrSession(), [20])->assertCreated();
        $this->submit($this->qrSession(), [20])->assertCreated();
        $this->assertSame(40.0, (float) DB::table('pos_order_items')->sum('qty'));
    }

    public static function roundModes(): array
    {
        return ['accepted rounds' => ['kitchen_direct'], 'pending rounds' => ['staff_confirm']];
    }

    #[DataProvider('roundModes')]
    public function test_dine_in_rounds_above_the_shelf_count_are_accepted_and_replay_once(string $mode): void
    {
        $session = $this->qrSession(true, $mode);
        $this->submit($session, [20], 'round-1')->assertCreated();
        $this->submit($session, [30], 'round-2')->assertCreated();
        $this->submit($session, [30], 'round-2')->assertSuccessful();
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_qr_order_rounds', 2);
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
    }

    public function test_a_pending_round_above_the_shelf_count_confirms_with_frozen_money(): void
    {
        $session = $this->qrSession(true);
        $this->submit($session, [30])->assertCreated();
        $round = QrOrderRound::query()->sole();
        $this->assertSame(QrOrderRound::STATUS_PENDING_CONFIRMATION, $round->status);
        $frozen = $round->total_baisas;
        app(ConfirmDineInQrRoundAction::class)->handle($this->device('fixed_pos'), $round->id);
        $this->assertSame($frozen, $round->fresh()->total_baisas);
        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $round->fresh()->status);
        $this->assertSame(30.0, (float) DB::table('pos_order_items')->sum('qty'));
    }

    private function device(string $type = 'payment_station'): Device
    {
        return Device::factory()->paired('mdev_stock_'.Str::random(16))->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => $type, 'status' => 'active',
        ]);
    }

    private function qrSession(bool $dineIn = false, string $mode = 'staff_confirm'): QrSession
    {
        $table = null;
        if ($dineIn) {
            DB::table('pos_branch_settings')->updateOrInsert(
                ['company_id' => 100, 'branch_id' => 10, 'key' => 'dine_in_round_mode'],
                ['value' => json_encode($mode), 'created_at' => now(), 'updated_at' => now()],
            );
            $floor = Floor::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
                'name' => 'Stock floor', 'status' => 'active',
            ]);
            $table = Table::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => $floor->id,
                'label' => 'T1', 'seats' => 4, 'shape' => 'square', 'status' => 'active',
                'qr_token' => Str::random(64),
            ]);
        }

        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $this->device()->id, 'table_id' => $table?->id,
            'token' => Str::random(64), 'token_expires_at' => now()->addMinute(),
            'status' => QrSession::STATUS_ACTIVE, 'client_secret_hash' => QrSession::hashClientSecret(self::SECRET),
            'bound_at' => now(), 'last_seen_at' => now(), 'expires_at' => now()->addHour(),
        ]);
    }

    private function submit(QrSession $session, array $quantities, ?string $requestId = null): TestResponse
    {
        $payload = [
            'client_request_id' => $requestId ?? (string) Str::uuid(), 'phone' => '90001234',
            'lines' => array_map(static fn (int $qty): array => [
                'product_id' => 105, 'qty' => $qty, 'addon_ids' => [], 'notes' => '',
            ], $quantities),
        ];
        if (! $session->isDineIn()) {
            $payload['checkout_choice'] = 'counter';
        } elseif (QrOrderRound::query()->where('qr_session_id', $session->id)
            ->where('status', QrOrderRound::STATUS_ACCEPTED)->exists()) {
            unset($payload['phone']);
        }

        return $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => self::SECRET])
            ->postJson('/api/v1/public/qr/'.($session->isDineIn() ? 'table-round' : 'checkout'), $payload);
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['pos_orders', 'pos_order_items', 'pos_qr_order_rounds', 'pos_qr_sessions',
            'pos_table_sessions', 'pos_table_session_events', 'pos_branch_product',
            'pos_product_stock_movements', 'pos_temp_reference_sequences', 'pos_customers'] as $table) {
            $rows[$table] = DB::table($table)->get()->map(static fn ($row): array => (array) $row)->all();
        }

        return $rows;
    }
}
