<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P3 P3-6 on a device staff table round: a round taken on the till or
 * handheld (possibly queued offline) copies the recipe in force when it was
 * taken — the event's client moment, clamped to now — not the one in force
 * when it reaches the server; a round held for review freezes that same copy
 * for its later confirmation.
 *
 * Latte (made-to-order): milk 0.250 l until an edit at 11:55, 0.300 l since.
 */
final class LaunchP3StaffRoundRecipeTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    private Device $device;

    private TableSession $seating;

    private Product $latte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-02 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->device = $this->seatingDevice();
        $this->seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $this->device->id]);
        $this->latte = Product::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte', 'base_price' => '1.500',
            'tax_rate' => '0.00', 'stock_mode' => 'ingredient', 'status' => 'active',
        ]);
        // The Latte existed before its recipe edits (fix order 1 M2 floors a
        // sale moment at the product's creation).
        DB::table('pos_products')->where('id', $this->latte->id)->update(['created_at' => '2026-09-01 08:00:00']);
        DB::table('pos_ingredients')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk', 'unit' => 'l',
            'default_unit_cost' => '0.400000', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_product_recipes')->insert([
            'product_id' => $this->latte->id, 'ingredient_id' => 1, 'quantity' => '0.3000', 'unit_at_set' => 'l',
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_product_recipe_versions')->insert([
            'product_id' => $this->latte->id,
            'recipe_json' => json_encode([['ingredient_id' => 1, 'ingredient_name' => 'Milk', 'quantity' => '0.2500', 'unit' => 'l', 'unit_cost_at_time' => '0.400000']]),
            'edited_by_user_id' => 1, 'note' => null, 'edited_at' => '2026-10-02 11:55:00',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function round(Carbon $takenAt, array $lines, string $outcome, array $extra = []): array
    {
        $payload = [
            'seating_key' => $this->seating->client_request_id, 'table_id' => (int) $this->seating->table_id,
            'queued_offline' => true, 'client_request_id' => (string) Str::uuid(),
            'submitted_at' => $takenAt->toIso8601String(), 'lines' => $lines,
        ];

        return $this->withToken($this->device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
            'client_timestamp' => $payload['submitted_at'], 'payload' => $payload,
        ] + $extra]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.outcome', $outcome)
            ->json('data.results.0.result');
    }

    /**
     * @return array<string, mixed>
     */
    private function latteLine(): array
    {
        return ['product_id' => (int) $this->latte->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null];
    }

    public function test_an_offline_staff_round_copies_the_recipe_in_force_when_it_was_taken(): void
    {
        $this->round(Carbon::parse('2026-10-02 11:50:00', 'UTC'), [$this->latteLine()], 'appended');
        $this->round(Carbon::parse('2026-10-02 11:58:00', 'UTC'), [$this->latteLine()], 'appended');

        $copied = OrderItem::query()->orderBy('id')->get()
            ->map(static fn (OrderItem $item): float => (float) $item->recipe_snapshot_json[0]['qty'])->all();
        $this->assertSame([0.25, 0.3], $copied);
    }

    public function test_a_staff_round_from_a_clock_reset_to_2000_copies_no_earlier_than_the_device_activation(): void
    {
        // Fix order 1 M2: the Latte was created with its recipe on 09-01 (a
        // "[]" first version); the handheld was activated on 09-20.
        DB::table('pos_product_recipe_versions')->insert([
            'product_id' => $this->latte->id, 'recipe_json' => '[]', 'edited_by_user_id' => 1, 'note' => null,
            'edited_at' => '2026-09-01 08:00:00',
        ]);
        $this->device->forceFill(['assignment_activated_at' => '2026-09-20 09:00:00', 'token_issued_at' => '2026-09-20 09:00:00'])->save();

        $this->round(Carbon::parse('2000-01-01 00:05:00', 'UTC'), [$this->latteLine()], 'appended', ['identity' => [
            'company_id' => 100, 'branch_id' => 10, 'device_uuid' => $this->device->uuid,
        ]]);

        $this->assertSame(0.25, (float) OrderItem::query()->sole()->recipe_snapshot_json[0]['qty']);
    }

    public function test_a_staff_round_held_for_review_freezes_the_same_copy(): void
    {
        $missing = $this->seatingProduct();
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => $missing->id, 'is_available' => false,
        ]);

        $ack = $this->round(Carbon::parse('2026-10-02 11:50:00', 'UTC'), [
            $this->latteLine(),
            ['product_id' => (int) $missing->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null],
        ], 'held');

        $payload = QrOrderRound::query()->findOrFail($ack['round_id'])->confirm_payload;
        $this->assertSame(0.25, (float) $payload['items'][0]['attributes']['recipe_snapshot_json'][0]['qty']);
    }
}
