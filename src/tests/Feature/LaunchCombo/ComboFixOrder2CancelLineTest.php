<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchCombo;

use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH combo add-on, Part A fix order 2 (C-21): a table line cancel names
 * the line it means. `cancel_line` matches product + meal_id + add-ons +
 * notes, and the combo / meal picks when the request sends them; a plain
 * main and a meal main never match each other. An old build (no meal_id)
 * keeps today's behaviour on plain lines; a meal line asked for without its
 * meal_id is refused (409 meal_id_required).
 */
final class ComboFixOrder2CancelLineTest extends TestCase
{
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private int $burgers;

    private int $drinks;

    private int $beef;

    private int $fries;

    private int $cola;

    private int $juice;

    private int $box;

    private int $boxDrink;

    private int $meal;

    private int $mealDrink;

    /** @var \Closure(array): TestResponse the last table's cancel over HTTP */
    private \Closure $cancelOverHttp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $this->seatingBranch();
        $this->burgers = $this->p4Category('Burgers');
        $this->drinks = $this->p4Category('Drinks');
        $this->beef = $this->p4Product('Beef burger', '2.000', ['category_id' => $this->burgers]);
        $this->fries = $this->p4Product('Fries', '1.000');
        $this->cola = $this->p4Product('Cola', '1.000', ['category_id' => $this->drinks]);
        $this->juice = $this->p4Product('Juice', '1.000', ['category_id' => $this->drinks]);
        $this->box = $this->p4Product('Family box', '5.000', ['product_type' => 'combo']);
        $this->p4FixedLine(['combo_product_id' => $this->box], $this->beef, 2, [], 0);
        $this->p4FixedLine(['combo_product_id' => $this->box], $this->fries, 1, [], 1);
        $this->boxDrink = $this->p4ChoiceLine(['combo_product_id' => $this->box], $this->drinks, 1, [], [], 2);
        $this->meal = $this->p4Meal('meal', '1.200', [$this->burgers], [], ['sort_order' => 1]);
        $this->p4FixedLine(['meal_id' => $this->meal], $this->fries, 1, [], 0);
        $this->mealDrink = $this->p4ChoiceLine(['meal_id' => $this->meal], $this->drinks, 1, [], [], 1);
    }

    /** A table with one sent round of these lines; returns a cancel function (extra payload => the sync result). */
    private function table(array $lines): \Closure
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $push = fn (string $type, array $payload): array => $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => $type, 'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
        ]]])->assertOk()->json('data.results.0');
        $base = ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false];
        $ack = $push('table.session.round', $base + ['client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(), 'lines' => $lines]);
        $this->assertSame('processed', $ack['status'], (string) json_encode($ack));

        $this->cancelOverHttp = fn (array $cancel) => $this->withToken($device->plainTextToken)->postJson('/api/v1/device/tables/'.$seating->uuid.'/cancel-line',
            $base + $cancel + ['client_request_id' => (string) Str::uuid(), 'qty' => 1, 'prepared' => false, 'cancelled_at' => now()->toIso8601String(), 'staff_id' => 7]);

        return fn (array $cancel): array => $push('table.session.cancel_line', $base + $cancel + ['client_request_id' => (string) Str::uuid(),
            'qty' => 1, 'prepared' => false, 'cancelled_at' => now()->toIso8601String(), 'staff_id' => 7]);
    }

    private function mealLine(int $drink): array
    {
        return ['product_id' => $this->beef, 'qty' => 1, 'meal_id' => $this->meal, 'combo' => [['line_id' => $this->mealDrink, 'product_id' => $drink, 'qty' => 1]]];
    }

    private function boxLine(int $drink): array
    {
        return ['product_id' => $this->box, 'qty' => 1, 'combo' => [['line_id' => $this->boxDrink, 'product_id' => $drink, 'qty' => 1]]];
    }

    /** @return array<string, string> top-level line => status ("plain" / "meal" / "box:<drink id>") */
    private function lineStatuses(): array
    {
        $out = [];
        foreach (OrderItem::query()->whereNull('parent_order_item_id')->orderBy('id')->get() as $item) {
            $drink = OrderItem::query()->where('parent_order_item_id', $item->id)->whereIn('product_id', [$this->cola, $this->juice])->value('product_id');
            $key = $item->meal_id !== null ? 'meal:'.$drink : ($item->product_id === $this->box ? 'box:'.$drink : 'plain');
            $out[$key] = $item->status;
        }

        return $out;
    }

    private function processed(array $result, int $cancelled = 1): void
    {
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertSame($cancelled, $result['result']['cancelled_qty'], (string) json_encode($result));
    }

    public function test_c21_cancelling_the_meal_leaves_the_plain_main(): void
    {
        // The meal first, the plain burger last (a cancel matched by product alone takes the last line).
        $cancel = $this->table([$this->mealLine($this->cola), ['product_id' => $this->beef, 'qty' => 1]]);
        $this->processed($cancel(['product_id' => $this->beef, 'meal_id' => $this->meal]));
        $this->assertSame(['meal:'.$this->cola => 'void', 'plain' => 'open'], $this->lineStatuses());
        // Asked again, nothing is left to cancel under the meal: the plain burger stays.
        $this->processed($cancel(['product_id' => $this->beef, 'meal_id' => $this->meal]), 0);
        $this->assertSame('open', $this->lineStatuses()['plain']);
    }

    public function test_c21_cancelling_the_plain_main_leaves_the_meal_and_a_meal_needs_its_meal_id(): void
    {
        // The plain burger first, the meal last.
        $cancel = $this->table([['product_id' => $this->beef, 'qty' => 1], $this->mealLine($this->cola)]);
        $this->processed($cancel(['product_id' => $this->beef]));
        $this->assertSame(['plain' => 'void', 'meal:'.$this->cola => 'open'], $this->lineStatuses());

        // Only the meal is left: a request without its meal_id (an old build) is refused, the meal stays.
        $refused = $cancel(['product_id' => $this->beef]);
        $this->assertSame('failed', $refused['status'], (string) json_encode($refused));
        $this->assertSame('meal_id_required', $refused['result']['refusal_code'] ?? null, (string) json_encode($refused));
        $this->assertStringContainsString('"Beef burger meal" is a meal', (string) $refused['result']['error']);
        // Online, the same request is a 409.
        ($this->cancelOverHttp)(['product_id' => $this->beef])->assertStatus(409)->assertJsonPath('errors.0.code', 'meal_id_required');
        $this->assertSame('open', $this->lineStatuses()['meal:'.$this->cola]);
        // With it, the meal goes.
        $this->processed($cancel(['product_id' => $this->beef, 'meal_id' => $this->meal]));
        $this->assertSame('void', $this->lineStatuses()['meal:'.$this->cola]);
    }

    public function test_c21_the_combo_picks_choose_the_line(): void
    {
        // Two boxes: Cola first, Juice last.
        $cancel = $this->table([$this->boxLine($this->cola), $this->boxLine($this->juice)]);
        $this->processed($cancel(['product_id' => $this->box, 'combo' => [['line_id' => $this->boxDrink, 'product_id' => $this->cola, 'qty' => 1]]]));
        $this->assertSame(['box:'.$this->cola => 'void', 'box:'.$this->juice => 'open'], $this->lineStatuses());
        // Picks no line has: nothing is cancelled.
        $this->processed($cancel(['product_id' => $this->box, 'combo' => [['line_id' => $this->boxDrink, 'product_id' => $this->cola, 'qty' => 1]]]), 0);
        $this->assertSame('open', $this->lineStatuses()['box:'.$this->juice]);
        // Two meals with different drinks: the picks choose between them too.
        $meals = $this->table([$this->mealLine($this->juice), $this->mealLine($this->cola)]);
        $this->processed($meals(['product_id' => $this->beef, 'meal_id' => $this->meal, 'combo' => [['line_id' => $this->mealDrink, 'product_id' => $this->juice, 'qty' => 1]]]));
        $this->assertSame(['meal:'.$this->juice => 'void', 'meal:'.$this->cola => 'open'], array_intersect_key($this->lineStatuses(), ['meal:'.$this->juice => 1, 'meal:'.$this->cola => 1]));
    }

    public function test_c21_an_old_build_keeps_todays_behaviour_on_plain_lines_and_combos(): void
    {
        // No meal_id, no picks: plain lines and combos cancel as before (the last matching line first).
        $cancel = $this->table([['product_id' => $this->beef, 'qty' => 2], $this->boxLine($this->cola), $this->boxLine($this->juice)]);
        $this->processed($cancel(['product_id' => $this->beef]));
        $this->processed($cancel(['product_id' => $this->box]));
        $this->assertSame(['plain' => 'open', 'box:'.$this->cola => 'open', 'box:'.$this->juice => 'void'], $this->lineStatuses());
        $this->assertSame('1.000', (string) OrderItem::query()->whereNull('parent_order_item_id')->where('product_id', $this->beef)->value('qty'));
    }
}
