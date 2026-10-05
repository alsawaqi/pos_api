<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchReview;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Models\Device;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\LaunchP3RecipeFixtures;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH review add-on, fix order A-1 (review R2 of part A, and H1 of the
 * pos_web review). Several cases are the reviewer's probes, kept as tests.
 *
 *  M1  one moment per config build; a build across Muscat midnight never
 *      skips a date boundary (the cursor's day is read a margin early)
 *  M2  a device count older than the breakdown's last change leaves the
 *      breakdown alone, and the counted-at stamps never move backwards
 *  L1  removes_ingredient_id acts only on an option of a Remove group
 *  L2  device config and QR menu agree on a combo's cooking time
 *  L3  a combo whose required slot has no available option is unavailable
 *  L6  a meal's price_from is the true cheapest completion
 *  L7  a first container count racing another device does not fail
 *  H1  line and combo-pick notes are one line, at most 140 characters
 */
final class FixOrderA1ReviewTest extends TestCase
{
    use LaunchP3RecipeFixtures;
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private function menuBase(string $utc): void
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $this->seatingBranch();
    }

    /** @return array<string, mixed> */
    private function config(string $token): array
    {
        return $this->withToken($token)->getJson('/api/v1/device/config')->assertOk()->json();
    }

    /** @return array<string, mixed> */
    private function delta(string $token, string $since): array
    {
        return $this->withToken($token)->getJson('/api/v1/device/config/delta?since='.urlencode($since))->assertOk()->json();
    }

    /** @return array<int, array<string, mixed>> */
    private function qrProducts(): array
    {
        return collect($this->p4QrGet($this->p4QrSession(), '/api/v1/public/qr/menu')->assertOk()->json('data.products'))
            ->keyBy('id')->all();
    }

    // ---- M1 ----

    public function test_m1_a_build_straddling_muscat_midnight_stamps_its_start_and_the_next_delta_moves_the_boundary(): void
    {
        $this->menuBase('2026-10-05 19:59:59'); // 23:59:59 Muscat, 5 Oct
        $summer = $this->p4Product('Summer juice', '1.000', ['on_sale_until' => '2026-10-05']);
        $pie = $this->p4Product('Autumn pie', '1.200', ['on_sale_from' => '2026-10-06']);
        $this->p4Device('mdev_a1_mid');

        // The clock reaches 00:00:00.4 (6 Oct) while the build runs.
        $moved = false;
        DB::listen(function ($query) use (&$moved): void {
            if (! $moved && str_contains($query->sql, 'on_sale_from')) {
                $moved = true;
                Carbon::setTestNow(Carbon::parse('2026-10-05 20:00:00.400', 'UTC'));
            }
        });
        $full = $this->config('mdev_a1_mid');
        $this->assertTrue($moved);
        $this->assertContains($summer, array_column($full['data']['products'], 'id'));
        // The cursor is the moment the catalogue was read (one moment for the whole build).
        $this->assertSame('2026-10-05T19:59:59+00:00', $full['meta']['generated_at']);

        $this->travelTo(Carbon::parse('2026-10-05 20:01:00', 'UTC'));
        $delta = $this->delta('mdev_a1_mid', $full['meta']['generated_at']);
        $this->assertContains($summer, $delta['data']['deleted']['products']);
        $this->assertSame([$pie], array_column($delta['data']['products'], 'id'));
        $this->assertSame('2026-10-05T20:01:00+00:00', $delta['meta']['generated_at']);
    }

    public function test_m1_a_cursor_stamped_just_after_midnight_by_an_older_build_still_sees_the_boundary(): void
    {
        $this->menuBase('2026-10-05 20:01:00'); // 00:01 Muscat, 6 Oct
        $summer = $this->p4Product('Summer juice', '1.000', ['on_sale_until' => '2026-10-05']);
        $pie = $this->p4Product('Autumn pie', '1.200', ['on_sale_from' => '2026-10-06']);
        $this->p4Device('mdev_a1_margin');

        // A build that read the catalogue at 23:59:59 and stamped its END (00:00:00.4) as the cursor.
        $delta = $this->delta('mdev_a1_margin', '2026-10-05T20:00:00+00:00');
        $this->assertContains($summer, $delta['data']['deleted']['products']);
        $this->assertSame([$pie], array_column($delta['data']['products'], 'id'));

        // Past the margin the boundary is settled: nothing moves again.
        $this->travelTo(Carbon::parse('2026-10-06 03:00:00', 'UTC'));
        $later = $this->delta('mdev_a1_margin', '2026-10-05T20:30:00+00:00');
        $this->assertSame([], $later['data']['products']);
        $this->assertSame([], $later['data']['deleted']['products']);
    }

    // ---- M2 and L7 ----

    private function countKitchen(): int
    {
        Device::factory()->paired('mdev_a1_count')->create(['company_id' => 100, 'branch_id' => 10]);
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_ingredients')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk',
            'unit' => 'ml', 'piece_unit_label' => 'bottle', 'units_per_piece' => '1000', 'allow_fractional_pieces' => true,
            'default_unit_cost' => '0.001000', 'status' => 'active'] + $t);
        $litre = (int) DB::table('pos_ingredient_units')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100,
            'ingredient_id' => 1, 'name' => 'bottle', 'factor' => '1000'] + $t);
        DB::table('pos_ingredients')->where('id', 1)->update(['count_container_id' => $litre]);
        DB::table('pos_branch_stock')->insert(['branch_id' => 10, 'ingredient_id' => 1, 'quantity' => '5000'] + $t);

        return $litre;
    }

    /** @return array<string, mixed> the push result of one stock.count line */
    private function pushCount(string $countedAt, array $line): array
    {
        return $this->withToken('mdev_a1_count')->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'stock.count', 'client_timestamp' => $countedAt,
            'payload' => ['staff_id' => 7, 'counted_at' => $countedAt, 'lines' => [['ingredient_id' => 1] + $line]],
        ]]])->assertOk()->json('data.results.0');
    }

    /** @return array{0: float, 1: string|null, 2: string|null, 3: float} [bottles, counted_at, total_count_at, total ml] */
    private function shelf(int $litre): array
    {
        $stock = DB::table('pos_branch_stock')->where('branch_id', 10)->where('ingredient_id', 1)->first();

        return [(float) DB::table('pos_stock_container_balances')->where('branch_id', 10)->where('container_id', $litre)->value('pieces'),
            $stock->containers_counted_at === null ? null : substr((string) $stock->containers_counted_at, 0, 19),
            $stock->containers_total_count_at === null ? null : substr((string) $stock->containers_total_count_at, 0, 19),
            (float) $stock->quantity];
    }

    public function test_m2_an_older_device_count_leaves_a_newer_breakdown_and_its_stamp_alone(): void
    {
        $this->seedPosStaff([7]);
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        $litre = $this->countKitchen();
        Log::spy();

        $this->assertSame('processed', $this->pushCount('2026-10-06T12:00:00+00:00', ['counted_pieces' => 8])['status']);
        $this->travelTo(Carbon::parse('2026-10-06 14:00:00', 'UTC'));
        $late = $this->pushCount('2026-10-06T10:00:00+00:00', ['counted_pieces' => 3]);

        $this->assertSame('processed', $late['status']);
        $this->assertSame([1], $late['result']['breakdown_unchanged_ingredient_ids']);
        // The 12:00 count is the newest knowledge of the shelf; the total reconciles as before (time-aware).
        $this->assertSame([8.0, '2026-10-06 12:00:00', '2026-10-06 10:00:00', 8000.0], $this->shelf($litre));
        $this->assertSame(['2026-10-06 12:00:00'], DB::table('pos_stock_container_movements')->pluck('occurred_at')
            ->map(static fn ($at): string => substr((string) $at, 0, 19))->all());
        Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_contains($message, 'breakdown changed after this count'))->once();

        // A late total-only count never moves containers_total_count_at back either.
        $this->assertSame('processed', $this->pushCount('2026-10-06T09:00:00+00:00', ['counted_units' => 4000])['status']);
        $this->assertSame('2026-10-06 10:00:00', $this->shelf($litre)[2]);
    }

    public function test_m2_a_count_older_than_a_later_breakdown_change_of_any_kind_is_skipped(): void
    {
        $this->seedPosStaff([7]);
        $this->travelTo(Carbon::parse('2026-10-06 08:00:00', 'UTC'));
        $litre = $this->countKitchen();
        // A delivery at 07:00 (part B's purchase) added 24 bottles to the breakdown.
        DB::table('pos_stock_container_balances')->insert(['company_id' => 100, 'branch_id' => 10, 'ingredient_id' => 1,
            'container_id' => $litre, 'pieces' => '24', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_stock_container_movements')->insert(['company_id' => 100, 'branch_id' => 10, 'ingredient_id' => 1,
            'container_id' => $litre, 'delta_pieces' => '24', 'pieces_after' => '24', 'reason' => 'purchase',
            'occurred_at' => '2026-10-06 07:00:00', 'created_at' => now()]);

        // Last night's 22:00 count (0 = clear) syncs this morning: the delivery stays on the shelf.
        $late = $this->pushCount('2026-10-05T22:00:00+00:00', ['counted_units' => 0]);
        $this->assertSame([1], $late['result']['breakdown_unchanged_ingredient_ids']);
        $this->assertSame(24.0, $this->shelf($litre)[0]);
        $this->assertSame(1, DB::table('pos_stock_container_movements')->count());
    }

    public function test_l7_a_first_container_count_racing_another_devices_still_counts(): void
    {
        $this->seedPosStaff([7]);
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        $litre = $this->countKitchen();
        // The handheld's count commits its first breakdown row right after this count read the (empty) breakdown.
        $raced = false;
        DB::listen(function ($query) use (&$raced, $litre): void {
            if (! $raced && str_starts_with($query->sql, 'select * from "pos_stock_container_balances"')) {
                $raced = true;
                DB::table('pos_stock_container_balances')->insert(['company_id' => 100, 'branch_id' => 10, 'ingredient_id' => 1,
                    'container_id' => $litre, 'pieces' => '2', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $result = $this->pushCount('2026-10-06T12:00:00+00:00', ['counted_pieces' => 6]);

        $this->assertTrue($raced);
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertSame(6000.0, $this->shelf($litre)[3]);
        $this->assertSame(6.0, $this->shelf($litre)[0]);
        // The ledger builds on the other device's row: 2 → 6.
        $this->assertSame([[4.0, 6.0]], DB::table('pos_stock_container_movements')->get()
            ->map(static fn (object $row): array => [(float) $row->delta_pieces, (float) $row->pieces_after])->all());
    }

    // ---- L1 ----

    public function test_l1_removes_ingredient_id_on_an_extras_option_never_removes_and_the_option_keeps_its_stock(): void
    {
        $this->menuBase('2026-10-06 09:00:00');
        $this->seedPrepKitchen();
        $extra = $this->p4Addons(self::SHAWARMA, ['Extra garlic' => '0.100'])['Extra garlic'];
        DB::table('pos_addon_consumptions')->insert(['add_on_id' => $extra, 'ingredient_id' => self::GARLIC, 'direction' => 'add',
            'quantity' => '5', 'unit' => 'g', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_addons')->where('id', $extra)->update(['removes_ingredient_id' => self::GARLIC]);

        $this->p4Device('mdev_a1_kind');
        $this->p4Push('mdev_a1_kind', [$this->p4Event('order.create', $this->p4Order([[
            'product_id' => self::SHAWARMA, 'qty' => 1, 'unit_price_baisas' => 1600, 'line_total_baisas' => 1600,
            'addons' => [['add_on_id' => $extra, 'price_delta_baisas' => 100]],
        ]]))]);
        $item = OrderItem::query()->sole();

        // The direct 5 g and the 6 g inside the sauce stay, and the option adds its 5 g.
        $this->assertEqualsWithDelta(11.0, (float) collect($item->recipe_snapshot_json)->where('ingredient_id', self::GARLIC)->sum('qty'), 0.0001);
        $this->assertSame([[self::GARLIC, 'add', 5.0]], array_map(static fn (array $line): array => [$line['ingredient_id'], $line['direction'], (float) $line['qty']],
            $item->addons()->sole()->consumption_snapshot_json));
    }

    // ---- L2 and L3 ----

    public function test_l2_the_device_config_and_the_qr_menu_agree_on_a_combo_without_its_own_cooking_time(): void
    {
        $this->menuBase('2026-10-06 09:00:00');
        $burger = $this->p4Product('Burger', '2.000', ['cooking_minutes' => 12]);
        $fries = $this->p4Product('Fries', '0.800', ['cooking_minutes' => 4]);
        $soup = $this->p4Product('Winter soup', '1.500', ['cooking_minutes' => 30, 'on_sale_from' => '2026-11-01']);
        $meal = $this->p4Combo('Meal', '2.500', [['Main', 1, 1, [$burger => '0.000', $soup => '0.000']], ['Side', 1, 1, [$fries => '0.000']]]);
        $box = $this->p4Combo('Box', '3.000', [['Main', 1, 1, [$burger => '0.000']]], ['cooking_minutes' => 20]);
        $this->p4Device('mdev_a1_cook');

        $device = collect($this->config('mdev_a1_cook')['data']['products'])->keyBy('id');
        $qr = $this->qrProducts();
        // Own value, else the longest option on sale today (not the 30-minute soup from November).
        $this->assertSame([12, 12], [$device[$meal['id']]['cooking_minutes'], $qr[$meal['id']]['cooking_minutes']]);
        $this->assertSame([20, 20], [$device[$box['id']]['cooking_minutes'], $qr[$box['id']]['cooking_minutes']]);
    }

    public function test_l3_a_combo_whose_required_slot_has_no_available_option_is_unavailable_and_offers_no_meal(): void
    {
        $this->menuBase('2026-10-06 09:00:00');
        $burger = $this->p4Product('Burger', '2.000');
        $summer = $this->p4Product('Summer juice', '1.000', ['on_sale_until' => '2026-10-05']);
        $cola = $this->p4Product('Cola', '0.500');
        $cake = $this->p4Product('Cake', '1.000', ['on_sale_until' => '2026-10-05']);
        $ended = $this->p4Combo('Summer meal', '2.500', [['Main', 1, 1, [$burger => '0.000']], ['Drink', 1, 1, [$summer => '0.000']]]);
        $soldOut = $this->p4Combo('Cola meal', '2.500', [['Main', 1, 1, [$burger => '0.000']], ['Drink', 1, 1, [$cola => '0.000']]]);
        $optional = $this->p4Combo('Burger plus', '2.200', [['Main', 1, 1, [$burger => '0.000']], ['Dessert', 0, 1, [$cake => '0.000']]]);
        DB::table('pos_combo_slots')->whereIn('id', [$ended['slots'][0], $soldOut['slots'][0], $optional['slots'][0]])->update(['is_main' => true]);
        DB::table('pos_product_sold_out')->insert(['company_id' => 100, 'branch_id' => 10, 'product_id' => $cola,
            'set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $menu = $this->qrProducts();
        $this->assertSame([false, 'outside_dates'], [$menu[$ended['id']]['available'], $menu[$ended['id']]['unavailable_reason']]);
        $this->assertSame([false, 'sold_out'], [$menu[$soldOut['id']]['available'], $menu[$soldOut['id']]['unavailable_reason']]);
        // An optional slot that emptied does not block the combo.
        $this->assertSame([true, null], [$menu[$optional['id']]['available'], $menu[$optional['id']]['unavailable_reason']]);
        // Only the combo that can be completed is offered as a meal.
        $this->assertSame([$optional['id']], array_column($menu[$burger]['meals'], 'combo_product_id'));
    }

    // ---- L6 ----

    public function test_l6_a_meal_is_priced_from_its_true_cheapest_completion(): void
    {
        $this->menuBase('2026-10-06 09:00:00');
        $burger = $this->p4Product('Burger', '2.000');
        $fries = $this->p4Product('Fries', '0.800');
        $salad = $this->p4Product('Salad', '0.900');
        $rings = $this->p4Product('Onion rings', '0.900');
        // The burger needs a size: Regular +0.200 or Large +0.500 (a required group, min 1).
        $this->p4Addons($burger, ['Large' => '0.500', 'Regular' => '0.200'], ['name' => 'Size', 'min_selections' => 1, 'max_selections' => 1, 'selection_mode' => 'single']);
        // The Side slot's default is the costly one; the cheapest (salad) is sold out.
        $meal = $this->p4Combo('Burger meal', '2.500', [
            ['Main', 1, 1, [$burger => '0.000']],
            ['Sides', 2, 2, [$fries => '0.400', $salad => '0.000', $rings => '0.100']],
        ]);
        DB::table('pos_combo_slots')->where('id', $meal['slots'][0])->update(['is_main' => true]);
        DB::table('pos_product_sold_out')->insert(['company_id' => 100, 'branch_id' => 10, 'product_id' => $salad,
            'set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        // A combo whose main slot is not a single pick is no meal.
        $double = $this->p4Combo('Double', '3.500', [['Burgers', 2, 2, [$burger => '0.000']]]);
        DB::table('pos_combo_slots')->where('id', $double['slots'][0])->update(['is_main' => true]);

        // 2.500 + Regular 0.200 + 2 × onion rings 0.100.
        $this->assertSame([[$meal['id'], 2900]], array_map(static fn (array $m): array => [$m['combo_product_id'], $m['price_from_baisas']],
            $this->qrProducts()[$burger]['meals']));
    }

    // ---- H1 ----

    public function test_h1_qr_notes_become_one_line_and_a_longer_than_140_note_is_refused(): void
    {
        $this->menuBase('2026-10-06 09:00:00');
        $burger = $this->p4Product('Burger', '2.000');
        $fries = $this->p4Product('Fries', '0.800');
        $meal = $this->p4Combo('Meal', '2.500', [['Main', 1, 1, [$burger => '0.000']], ['Side', 1, 1, [$fries => '0.000']]]);
        $session = $this->p4QrSession();
        $raw = "  Well\r\ndone\tplease\u{0001}\u{2028}  no\u{0085}  onion  ";
        $line = fn (?string $note, ?string $pick = null) => [
            ['product_id' => $burger, 'qty' => 1, 'addon_ids' => [], 'notes' => $note],
            ['product_id' => $meal['id'], 'qty' => 1, 'addon_ids' => [], 'notes' => null, 'combo' => [
                ['slot_id' => $meal['slots'][0], 'product_id' => $burger, 'qty' => 1, 'addon_ids' => [], 'notes' => $pick],
                ['slot_id' => $meal['slots'][1], 'product_id' => $fries, 'qty' => 1, 'addon_ids' => [], 'notes' => null],
            ]],
        ];

        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => $line($raw, "Sauce\non the\tside")])->assertOk()
            ->assertJsonPath('data.quote.lines.0.notes', 'Well done please no onion')
            ->assertJsonPath('data.quote.lines.1.components.0.notes', 'Sauce on the side');
        // 140 after cleaning is accepted (the raw text was longer); 141 is refused, on a line and on a pick.
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => $line(str_repeat('a ', 70).str_repeat("\n", 30))])->assertOk();
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => $line(str_repeat('é', 141))])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'validation_failed');
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => $line(null, str_repeat('b', 141))])->assertStatus(422);

        $this->p4QrPost($session, '/api/v1/public/qr/checkout', $this->p4QrCheckout($line($raw, "Sauce\non the\tside")))->assertStatus(201);
        $this->assertSame(['Well done please no onion', 'Sauce on the side'],
            OrderItem::query()->whereNotNull('notes')->orderBy('id')->pluck('notes')->all());
        $this->p4QrPost($session, '/api/v1/public/qr/checkout', $this->p4QrCheckout($line(str_repeat('x', 141)), 'a1-long'))->assertStatus(422);
    }

    public function test_h1_a_dine_in_round_refuses_a_long_note_and_cleans_a_short_one(): void
    {
        $this->menuBase('2026-10-06 09:00:00');
        DB::table('pos_branch_settings')->insert(['company_id' => 100, 'branch_id' => 10, 'key' => 'qr_table_card_enabled', 'value' => '"on"']);
        $burger = $this->p4Product('Burger', '2.000');
        $bind = app(BindQrTableSessionAction::class)->handle($this->seatingTable()->qr_token, 'owner');
        $this->withHeaders(['X-QR-Session' => $bind['session_uuid'], 'X-QR-Client-Secret' => 'owner']);
        $round = fn (string $id, string $note) => $this->postJson('/api/v1/public/qr/table-round', ['client_request_id' => $id,
            'lines' => [['product_id' => $burger, 'qty' => 1, 'addon_ids' => [], 'notes' => $note]]]);

        $round('a1-long', str_repeat('z', 141))->assertStatus(422);
        $round('a1-short', "No\nonion")->assertCreated()->assertJsonPath('data.round.priced_lines.0.notes', 'No onion');
    }

    public function test_h1_device_notes_are_cleaned_and_cut_never_refused(): void
    {
        $this->menuBase('2026-10-06 09:00:00');
        $burger = $this->p4Product('Burger', '2.000');
        $fries = $this->p4Product('Fries', '0.800');
        $meal = $this->p4Combo('Meal', '2.500', [['Main', 1, 1, [$burger => '0.000']], ['Side', 1, 1, [$fries => '0.000']]]);
        $this->p4Device('mdev_a1_notes');
        $long = "Line one\r\nline two ".str_repeat('y', 200);
        $result = $this->p4Push('mdev_a1_notes', [$this->p4Event('order.create', $this->p4Order([
            ['product_id' => $burger, 'qty' => 1, 'unit_price_baisas' => 2000, 'line_total_baisas' => 2000, 'notes' => $long],
            ['product_id' => $meal['id'], 'qty' => 1, 'unit_price_baisas' => 2500, 'line_total_baisas' => 2500, 'combo' => [
                ['slot_id' => $meal['slots'][0], 'product_id' => $burger, 'qty' => 1, 'extra_price_baisas' => 0, 'notes' => "Well\tdone"],
                ['slot_id' => $meal['slots'][1], 'product_id' => $fries, 'qty' => 1, 'extra_price_baisas' => 0],
            ]],
        ]))])->json('data.results.0');

        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $notes = OrderItem::query()->whereNotNull('notes')->orderBy('id')->pluck('notes')->all();
        $this->assertSame(140, mb_strlen($notes[0]));
        $this->assertStringStartsWith('Line one line two yyy', $notes[0]);
        $this->assertSame('Well done', $notes[1]);
    }
}
