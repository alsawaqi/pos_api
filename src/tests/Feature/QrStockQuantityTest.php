<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Actions\Qr\RejectDineInQrRoundAction;
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

    public static function oversizedCarts(): array
    {
        return ['one line' => [[25]], 'duplicate product lines' => [[12, 12]],
            'exact stock plus one' => [[23, 1]]];
    }

    #[DataProvider('oversizedCarts')]
    public function test_oversized_quick_checkout_is_rejected_without_any_order_or_stock_write(array $quantities): void
    {
        $session = $this->qrSession();
        $before = $this->rows();
        $this->submit($session, $quantities)->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'product_unavailable');
        $this->assertSame($before, $this->rows());
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseCount('pos_order_items', 0);
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
    }

    public function test_exact_stock_is_accepted_and_idempotent_replay_does_not_reserve_twice(): void
    {
        $session = $this->qrSession();
        $first = $this->submit($session, [12, 11], 'same')->assertCreated();
        $before = $this->rows();
        $this->submit($session, [12, 11], 'same')->assertCreated()->assertExactJson($first->json());
        $this->assertSame($before, $this->rows());
        $this->assertSame(23.0, (float) DB::table('pos_order_items')->sum('qty'));
        $this->assertSame(23.0, (float) DB::table('pos_branch_product')->value('stock_qty'));
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
        $this->submit($this->qrSession(), [1])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'product_unavailable');
    }

    public static function untrackedBalances(): array
    {
        return ['untracked product' => ['untracked', '23.000'],
            'null branch stock' => ['unit', null]];
    }

    #[DataProvider('untrackedBalances')]
    public function test_untracked_and_null_balances_keep_existing_behavior(string $mode, ?string $balance): void
    {
        DB::table('pos_products')->update(['stock_mode' => $mode]);
        DB::table('pos_branch_product')->update(['stock_qty' => $balance]);
        $this->submit($this->qrSession(), [25])->assertCreated();
    }

    public function test_cooked_product_uses_branch_quantity_too(): void
    {
        DB::table('pos_products')->update(['stock_mode' => 'cooked']);
        $this->submit($this->qrSession(), [24])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'product_unavailable');
        $this->submit($this->qrSession(), [23])->assertCreated();
    }

    public function test_fractional_balance_is_not_rounded_up_to_a_whole_unit(): void
    {
        DB::table('pos_branch_product')->update(['stock_qty' => '22.999']);
        $this->submit($this->qrSession(), [23])->assertStatus(422);
        $this->submit($this->qrSession(), [22])->assertCreated();
    }

    public function test_two_customers_cannot_spend_the_same_unpaid_stock(): void
    {
        $this->submit($this->qrSession(), [20])->assertCreated();
        $second = $this->qrSession();
        $before = $this->rows();
        $this->submit($second, [4])->assertStatus(422)->assertJsonPath('errors.0.code', 'product_unavailable');
        $this->assertSame($before, $this->rows());
        $this->submit($second, [3])->assertCreated();
        $this->assertSame(23.0, (float) DB::table('pos_order_items')->sum('qty'));
    }

    public static function roundModes(): array
    {
        return ['accepted rounds' => ['kitchen_direct'], 'pending rounds' => ['staff_confirm']];
    }

    #[DataProvider('roundModes')]
    public function test_dine_in_accumulates_all_rounds_and_replays_without_double_counting(string $mode): void
    {
        $session = $this->qrSession(true, $mode);
        $this->submit($session, [20], 'round-1')->assertCreated();
        $before = $this->rows();
        $this->submit($session, [4], 'round-2')->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'product_unavailable');
        $this->assertSame($before, $this->rows());
        $this->submit($session, [3], 'round-2')->assertCreated();
        $this->submit($session, [3], 'round-2')->assertSuccessful();
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_qr_order_rounds', 2);
        $this->submit($this->qrSession(), [1])->assertStatus(422);
    }

    public function test_pending_confirmation_then_acceptance_keeps_one_commitment_and_frozen_money(): void
    {
        $session = $this->qrSession(true);
        $this->submit($session, [20])->assertCreated();
        $round = QrOrderRound::query()->sole();
        $this->assertSame(QrOrderRound::STATUS_PENDING_CONFIRMATION, $round->status);
        $this->assertDatabaseCount('pos_order_items', 0);
        $this->submit($this->qrSession(), [4])->assertStatus(422);
        $frozen = $round->total_baisas;
        app(ConfirmDineInQrRoundAction::class)->handle($this->device('fixed_pos'), $round->id);
        $this->assertSame($frozen, $round->fresh()->total_baisas);
        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $round->fresh()->status);
        $this->submit($this->qrSession(), [3])->assertCreated();
        $this->submit($this->qrSession(), [1])->assertStatus(422);
    }

    public function test_rejected_pending_round_releases_its_commitment(): void
    {
        $this->submit($this->qrSession(true), [23])->assertCreated();
        $this->submit($this->qrSession(), [1])->assertStatus(422);
        app(RejectDineInQrRoundAction::class)->handle($this->device('fixed_pos'), QrOrderRound::query()->sole()->id);
        $this->submit($this->qrSession(), [23])->assertCreated();
    }

    public function test_paid_and_pending_verification_items_are_not_counted_against_already_deducted_stock(): void
    {
        foreach ([Order::STATUS_PAID, Order::STATUS_PENDING_VERIFICATION] as $status) {
            $this->submit($this->qrSession(), [23])->assertCreated();
            Order::query()->where('status', Order::STATUS_HELD)->update(['status' => $status]);
        }
        // 23 here represents the remaining balance after historic sales.
        $this->submit($this->qrSession(), [23])->assertCreated();
        $this->assertDatabaseCount('pos_orders', 3);
    }

    public function test_void_order_or_void_item_does_not_keep_a_commitment(): void
    {
        $this->submit($this->qrSession(), [23])->assertCreated();
        Order::query()->update(['status' => Order::STATUS_VOID]);
        $this->submit($this->qrSession(), [23])->assertCreated();
        DB::table('pos_order_items')->update(['status' => 'void']);
        $this->submit($this->qrSession(), [23])->assertCreated();
    }

    public function test_other_branch_or_company_commitments_are_not_counted(): void
    {
        $this->submit($this->qrSession(), [23])->assertCreated();
        Order::query()->update(['branch_id' => 20]);
        $this->submit($this->qrSession(), [23])->assertCreated();
        Order::query()->where('branch_id', 10)->update(['company_id' => 200]);
        $this->submit($this->qrSession(), [23])->assertCreated();
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
