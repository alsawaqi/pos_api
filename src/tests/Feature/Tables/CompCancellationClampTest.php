<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Models\CompReason;
use App\Models\Discount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * F-19 regressions using the independent tester's real-route fixture helpers.
 * Bills are built through the REAL device routes (open -> round, priced by the server);
 * expected money comes from the tester's own integer implementation of the work-order formula.
 */
final class CompCancellationClampTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-19 18:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seatingBranch();
    }

    // ---------- tester's own arithmetic (integers only, half-up) ----------
    private static function expected(int $s, int $t, int $g, int $m, int $c): array
    {
        $dr = $s + $t - $g;
        $net = $s - $dr;
        $base = $net - $m - $c;
        $tax = ($m === 0 && $c === 0) ? $t : ($net === 0 ? 0 : intdiv(2 * $t * $base + $net, 2 * $net));

        return ['subtotal' => $s, 'discount_total' => $dr + $m, 'comp_total' => $c, 'tax_total' => $tax,
            'grand_total' => ($m === 0 && $c === 0) ? $g : $base + $tax];
    }

    private static function halfUp(int $num, int $den): int
    {
        return intdiv(2 * $num + $den, 2 * $den);
    }

    private static function b(mixed $omr): int
    {
        return (int) round(((float) $omr) * 1000);
    }

    // ---------- fixture helpers (real routes) ----------
    private function product(string $price, string $taxRate, string $name): int
    {
        $id = (int) DB::table('pos_products')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => $name,
            'base_price' => $price, 'tax_rate' => $taxRate, 'stock_mode' => 'untracked', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $id, 'is_available' => true, 'stock_qty' => null]);

        return $id;
    }

    private function as($device, string $method, string $path, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->device_token)->json($method, '/api/v1/device/'.$path, $data);
    }

    /** @return array{key:string, table:int, uuid:string, order:string} */
    private function openWithRound($device, array $lines, ?object $table = null): array
    {
        $table ??= $this->seatingTable('T65 tester '.Str::random(4));
        $seat = ['seating_key' => (string) Str::uuid(), 'table_id' => (int) $table->id, 'queued_offline' => false];
        $open = $this->as($device, 'POST', 'tables/open', $seat + ['opened_at' => now()->toIso8601String()])->assertOk();
        $seat['uuid'] = $open->json('data.table_session_uuid');
        $seat['order'] = $this->round($device, $seat, $lines);

        return ['key' => $seat['seating_key'], 'table' => $seat['table_id'], 'uuid' => $seat['uuid'], 'order' => $seat['order']];
    }

    private function round($device, array $seat, array $lines): string
    {
        $key = $seat['key'] ?? $seat['seating_key'];
        $table = $seat['table'] ?? $seat['table_id'];
        $r = $this->as($device, 'POST', 'tables/'.$seat['uuid'].'/round', ['seating_key' => $key, 'table_id' => $table,
            'queued_offline' => false, 'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => array_map(fn ($l) => ['product_id' => $l[0], 'qty' => $l[1], 'addon_ids' => []], $lines)]);
        $r->assertOk();
        $this->assertSame('accepted', $r->json('data.round_status'), $r->getContent());

        return (string) $r->json('data.order_uuid');
    }

    private function intent(array $seat, array $adjustment, ?string $id = null): array
    {
        return ['table_id' => $seat['table'], 'seating_key' => $seat['key'], 'queued_offline' => false,
            'client_request_id' => $id ?? (string) Str::uuid(), 'adjustment' => $adjustment];
    }

    private function adjust($device, array $seat, array $adjustment, ?string $id = null)
    {
        return $this->as($device, 'POST', 'tables/'.$seat['uuid'].'/adjust', $this->intent($seat, $adjustment, $id));
    }

    private function sums(string $orderUuid): array
    {
        $id = DB::table('pos_orders')->where('uuid', $orderUuid)->value('id');
        $r = DB::table('pos_qr_order_rounds')->where('order_id', $id)->where('status', 'accepted')
            ->selectRaw('COALESCE(SUM(subtotal_baisas), 0) s, COALESCE(SUM(tax_baisas), 0) t, COALESCE(SUM(total_baisas), 0) g')->first();

        return [(int) $r->s, (int) $r->t, (int) $r->g];
    }

    private function header(string $orderUuid): array
    {
        $o = DB::table('pos_orders')->where('uuid', $orderUuid)->first();

        return ['subtotal' => self::b($o->subtotal), 'discount_total' => self::b($o->discount_total), 'comp_total' => self::b($o->comp_total ?? 0),
            'tax_total' => self::b($o->tax_total), 'grand_total' => self::b($o->grand_total)];
    }

    private function manualRows(string $orderUuid): array
    {
        $id = DB::table('pos_orders')->where('uuid', $orderUuid)->value('id');

        return DB::table('pos_order_discounts')->where('order_id', $id)->where('amount_type_snapshot', 'like', 'table_manual_%')
            ->orderBy('id')->get()->map(fn ($r) => [$r->amount_type_snapshot, self::b($r->amount)])->all();
    }

    private function compRows(string $orderUuid): array
    {
        $id = DB::table('pos_orders')->where('uuid', $orderUuid)->value('id');

        return DB::table('pos_order_comps')->where('order_id', $id)->orderBy('id')->get()->map(fn ($r) => self::b($r->amount))->all();
    }

    private function roundsRaw(string $orderUuid): array
    {
        $id = DB::table('pos_orders')->where('uuid', $orderUuid)->value('id');

        return DB::table('pos_qr_order_rounds')->where('order_id', $id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function vatBill($device, bool $withAutoRule = true, int $latteQty = 2): array
    {
        if ($withAutoRule) {
            Discount::query()->create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Tester auto 10', 'scope' => 'order',
                'amount_type' => 'percent', 'amount' => 10, 'status' => 'active', 'auto_apply' => true]);
        }
        // Tax comes from the company tax catalogue (pos_taxes), applied to the base after discounts.
        DB::table('pos_taxes')->insert(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'VAT', 'rate_percent' => '5.00',
            'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $a = $this->product('1.250', '5.00', 'Tester latte');
        $b = $this->product('0.700', '5.00', 'Tester cake');
        $seat = $this->openWithRound($device, [[$a, $latteQty], [$b, 3]]);

        return [$seat, $a, $b];
    }

    private function comp($device, array $seat, int $qty): object
    {
        $reason = CompReason::query()->create(['uuid' => Str::uuid(), 'company_id' => 100,
            'code' => 'FIX19', 'name' => 'Original reason', 'is_active' => true]);
        $staff = DB::table('pos_staff')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100,
            'branch_id' => 10, 'name' => 'Original approver', 'pin_hash' => 'synthetic', 'position' => 'manager', 'status' => 'active']);
        $line = json_decode($this->roundsRaw($seat['order'])[0]['priced_lines'], true)[0];
        $this->adjust($device, $seat, ['kind' => 'comp', 'mode' => 'apply', 'comp_reason_id' => $reason->id,
            'target' => ['order_item_id' => $line['order_item_id'], 'qty' => $qty],
            'authorized_by' => 'Original approver', 'approved_by_staff_id' => $staff, 'note' => 'Original note'])->assertOk();

        return DB::table('pos_order_comps')->sole();
    }

    private function cancel($device, array $seat, int $product, int $qty, ?string $id = null)
    {
        return $this->as($device, 'POST', 'tables/'.$seat['uuid'].'/cancel-line', [
            'seating_key' => $seat['key'], 'table_id' => $seat['table'], 'queued_offline' => false,
            'client_request_id' => $id ?? (string) Str::uuid(), 'product_id' => $product, 'addon_ids' => [],
            'notes' => null, 'qty' => $qty, 'prepared' => false, 'cancelled_at' => now()->toIso8601String()])->assertOk();
    }

    private function clampEvents(): array
    {
        return DB::table('pos_table_session_events')->where('event_type', 'adjusted')->get()
            ->map(fn ($row) => json_decode($row->payload, true))
            ->filter(fn ($p) => ($p['kind'] ?? null) === 'comp' && ($p['mode'] ?? null) === 'clamp')->values()->all();
    }

    public function test_full_comp_then_full_cancel_reverses_only_its_line_and_replay_is_idempotent(): void
    {
        $device = $this->seatingDevice();
        [$seat, $latte] = $this->vatBill($device, false);
        $source = $this->comp($device, $seat, 2);
        $request = (string) Str::uuid();
        $this->cancel($device, $seat, $latte, 2, $request)->assertJsonPath('data.cancelled_qty', 2);
        $this->assertSame([2100, 105, 2205], $this->sums($seat['order']));
        $this->assertSame(['subtotal' => 2100, 'discount_total' => 0, 'comp_total' => 0, 'tax_total' => 105, 'grand_total' => 2205], $this->header($seat['order']));
        $this->assertSame([2500, -2500], $this->compRows($seat['order']));
        $this->assertEquals($source, DB::table('pos_order_comps')->where('id', $source->id)->sole());
        $reversal = DB::table('pos_order_comps')->orderByDesc('id')->first();
        foreach (['company_id', 'branch_id', 'order_id', 'order_item_id', 'qty', 'comp_reason_id', 'reason_code_snapshot', 'reason_name_snapshot', 'approved_by_pos_staff_id', 'note'] as $field) {
            $this->assertSame($source->$field, $reversal->$field, $field);
        }
        $events = $this->clampEvents();
        $this->assertCount(1, $events);
        $this->assertSame(-2500, $events[0]['amount_baisas']);
        $this->assertSame(0, $events[0]['remaining_amount_baisas']);
        $this->assertSame((int) $source->id, $events[0]['source_comp_id']);
        foreach (['Original reason', 'Original approver', 'Original note'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($events));
        }
        $rounds = $this->roundsRaw($seat['order']);
        $this->cancel($device, $seat, $latte, 2, $request)->assertJsonPath('data.outcome', 'replayed');
        $this->assertSame([2500, -2500], $this->compRows($seat['order']));
        $this->assertSame($events, $this->clampEvents());
        $this->assertSame($rounds, $this->roundsRaw($seat['order']));
    }

    public function test_two_of_three_comped_then_two_cancelled_caps_to_one_and_presenter_marks_changed_comp(): void
    {
        $device = $this->seatingDevice();
        [$seat, $latte] = $this->vatBill($device, false, 3);
        $this->comp($device, $seat, 2);
        $this->cancel($device, $seat, $latte, 2);
        $this->assertSame([3350, 168, 3518], $this->sums($seat['order']));
        $this->assertSame([2500, -1250], $this->compRows($seat['order']));
        $this->assertSame(['subtotal' => 3350, 'discount_total' => 0, 'comp_total' => 1250, 'tax_total' => 105, 'grand_total' => 2205], $this->header($seat['order']));
        $this->as($device, 'GET', 'tables/'.$seat['table'].'/detail')->assertOk()
            ->assertJsonPath('data.bill.comp_total_baisas', 1250)
            ->assertJsonPath('data.bill.adjustment_state.comp.amount_baisas', 1250)
            ->assertJsonPath('data.bill.adjustment_state.comp.stale', true);
        $this->assertCount(1, $this->clampEvents());
    }

    public function test_cancel_another_line_does_not_reverse_the_comp(): void
    {
        $device = $this->seatingDevice();
        [$seat, , $cake] = $this->vatBill($device, false);
        $source = $this->comp($device, $seat, 1);
        $this->cancel($device, $seat, $cake, 1);
        $this->assertSame([1250], $this->compRows($seat['order']));
        $this->assertSame([], $this->clampEvents());
        $this->assertEquals($source, DB::table('pos_order_comps')->sole());
        $this->assertSame(['subtotal' => 3900, 'discount_total' => 0, 'comp_total' => 1250, 'tax_total' => 133, 'grand_total' => 2783], $this->header($seat['order']));
    }

    public function test_cancel_before_comp_still_refuses_more_than_the_remaining_quantity(): void
    {
        $device = $this->seatingDevice();
        [$seat, $latte] = $this->vatBill($device, false);
        $this->cancel($device, $seat, $latte, 1);
        $source = $this->comp($device, $seat, 1);
        $this->adjust($device, $seat, ['kind' => 'comp', 'mode' => 'apply', 'comp_reason_id' => $source->comp_reason_id,
            'target' => ['order_item_id' => $source->order_item_id, 'qty' => 2], 'authorized_by' => 'Manager'])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'adjustment_exceeds_bill');
        $this->assertSame([1250], $this->compRows($seat['order']));
    }

    public function test_line_cap_runs_before_total_clamp_so_unrelated_discount_is_preserved(): void
    {
        $device = $this->seatingDevice();
        [$seat, $latte] = $this->vatBill($device, false);
        $this->comp($device, $seat, 2);
        $this->adjust($device, $seat, ['kind' => 'discount', 'mode' => 'fixed', 'amount_baisas' => 1000, 'label' => 'Fixed'])->assertOk();
        $this->cancel($device, $seat, $latte, 2);
        $this->assertSame([['table_manual_fixed', 1000]], $this->manualRows($seat['order']));
        $this->assertSame([2500, -2500], $this->compRows($seat['order']));
        $this->assertSame(['subtotal' => 2100, 'discount_total' => 1000, 'comp_total' => 0, 'tax_total' => 55, 'grand_total' => 1155], $this->header($seat['order']));
    }

    public function test_claim_and_exact_payment_use_the_line_capped_total(): void
    {
        $device = $this->seatingDevice();
        [$seat, $latte] = $this->vatBill($device, false);
        $this->comp($device, $seat, 2);
        $this->cancel($device, $seat, $latte, 2);
        $this->as($device, 'POST', 'qr/claim-settlement', ['order_uuid' => $seat['order']])->assertOk()->assertJsonPath('data.charge_amount_baisas', 2205);
        $pay = fn (int $amount) => $this->as($device, 'POST', 'sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $seat['order'], 'paid_at' => now()->toIso8601String(), 'payments' => [['method' => 'cash', 'amount_baisas' => $amount]]],
        ]]])->assertOk();
        $pay(2204)->assertJsonPath('data.results.0.status', 'failed');
        $this->assertDatabaseCount('pos_payments', 0);
        $pay(2205)->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(2205, self::b(DB::table('pos_payments')->sole()->amount));
        $this->assertSame('closed', DB::table('pos_table_sessions')->where('uuid', $seat['uuid'])->value('status'));
        $this->assertSame(1, count($this->clampEvents()));
    }

    public function test_obs17_only_percentage_discounts_become_stale_after_a_round(): void
    {
        $device = $this->seatingDevice();
        foreach (['fixed', 'percent', 'rule_fixed', 'rule_percent'] as $mode) {
            $product = $this->product('1.000', '0', 'OBS17 '.$mode);
            $seat = $this->openWithRound($device, [[$product, 3]]);
            if (str_starts_with($mode, 'rule_')) {
                $rule = Discount::query()->create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => $mode,
                    'scope' => 'order', 'amount_type' => substr($mode, 5), 'amount' => $mode === 'rule_fixed' ? '0.100' : 10,
                    'status' => 'active', 'auto_apply' => false]);
                $intent = ['kind' => 'discount', 'mode' => 'rule', 'discount_id' => $rule->id];
            } else {
                $intent = ['kind' => 'discount', 'mode' => $mode, 'label' => 'OBS17',
                    $mode === 'fixed' ? 'amount_baisas' : 'percent_bp' => $mode === 'fixed' ? 100 : 1000];
            }
            $this->adjust($device, $seat, $intent)->assertOk();
            $before = $this->header($seat['order'])['discount_total'];
            $this->round($device, $seat, [[$product, 1]]);
            $this->as($device, 'GET', 'tables/'.$seat['table'].'/detail')->assertOk()
                ->assertJsonPath('data.bill.adjustment_state.discount.stale', str_contains($mode, 'percent'));
            $this->assertSame($before, $this->header($seat['order'])['discount_total']);
        }
    }
}
