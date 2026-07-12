<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Expense;
use App\Models\RestockRequestLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 8.8 — expense.log + restock.request sync handlers.
 *
 * A paired device (company 100 / branch 10) logs expenses and submits restock
 * requests via /sync/push; both are processed into the shared pos_* tables for
 * the merchant portal's review surfaces.
 */
class DeviceSyncExpenseRestockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 4 — the expense.log recorder (staff 7) must exist in the tenant.
        $this->seedPosStaff([7]);
    }

    private function device(string $token = 'mdev_x', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedIngredient(array $overrides = []): void
    {
        DB::table('pos_ingredients')->insert(array_merge([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Milk', 'unit' => 'l', 'default_unit_cost' => 0.400, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function expenseEvent(array $payload = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'expense.log',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => array_merge(['category' => 'utilities', 'amount_baisas' => 5000, 'note' => 'water bill', 'staff_id' => 7], $payload),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function restockEvent(array $lines): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'restock.request',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['lines' => $lines, 'note' => 'low on milk'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_expense_log_records_an_expense(): void
    {
        $this->device();

        $res = $this->push('mdev_x', [$this->expenseEvent()])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertNotNull($r['result']['expense_id']);
        $this->assertDatabaseHas('pos_expenses', [
            'company_id' => 100, 'branch_id' => 10, 'category' => 'utilities',
            'status' => 'recorded', 'logged_by_pos_staff_id' => 7,
        ]);
        $this->assertSame('5.000', Expense::firstOrFail()->amount);
    }

    public function test_expense_log_rejects_an_unknown_category(): void
    {
        $this->device();

        $res = $this->push('mdev_x', [$this->expenseEvent(['category' => 'bogus'])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('category', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_expenses', 0);
    }

    public function test_expense_log_validates_against_company_categories_when_present(): void
    {
        $this->device();
        // v2 #7: once the company has seeded categories, the per-company set
        // REPLACES the legacy fallback.
        DB::table('pos_expense_categories')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Marketing', 'name_ar' => null, 'key' => 'marketing', 'is_active' => true, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A seeded company key is accepted.
        $ok = $this->push('mdev_x', [$this->expenseEvent(['category' => 'marketing'])])->assertOk();
        $this->assertSame('processed', $ok->json('data.results.0.status'));

        // A legacy key the company did NOT seed is now rejected.
        $bad = $this->push('mdev_x', [$this->expenseEvent(['category' => 'utilities'])])->assertOk();
        $this->assertSame('failed', $bad->json('data.results.0.status'));
    }

    public function test_replaying_an_expense_does_not_duplicate(): void
    {
        $this->device();
        $event = $this->expenseEvent();

        $this->push('mdev_x', [$event])->assertOk();
        $res = $this->push('mdev_x', [$event])->assertOk();

        $res->assertJsonPath('data.summary.duplicates', 1);
        $this->assertDatabaseCount('pos_expenses', 1);
    }

    public function test_restock_request_creates_a_request_with_lines(): void
    {
        $this->device();
        $this->seedIngredient();

        $res = $this->push('mdev_x', [$this->restockEvent([['ingredient_id' => 1, 'quantity' => 5]])])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertSame('submitted', $r['result']['status']);
        $this->assertSame(1, $r['result']['lines']);
        $this->assertDatabaseHas('pos_restock_requests', ['company_id' => 100, 'branch_id' => 10, 'status' => 'submitted']);
        $line = RestockRequestLine::firstOrFail();
        $this->assertSame(1, (int) $line->ingredient_id);
        $this->assertSame('l', $line->unit_at_set);            // snapshotted from the ingredient
        $this->assertSame('5.000', $line->quantity_requested);
    }

    public function test_restock_rejects_an_unknown_ingredient(): void
    {
        $this->device();

        $res = $this->push('mdev_x', [$this->restockEvent([['ingredient_id' => 999, 'quantity' => 5]])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('unknown ingredient', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_restock_requests', 0); // transaction rolled back
    }

    public function test_restock_rejects_a_cross_tenant_ingredient(): void
    {
        $this->device(); // company 100
        $this->seedIngredient(['id' => 2, 'company_id' => 200]);

        $res = $this->push('mdev_x', [$this->restockEvent([['ingredient_id' => 2, 'quantity' => 5]])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertDatabaseCount('pos_restock_requests', 0);
    }

    public function test_restock_rejects_a_duplicate_ingredient_line(): void
    {
        $this->device();
        $this->seedIngredient();

        $res = $this->push('mdev_x', [$this->restockEvent([
            ['ingredient_id' => 1, 'quantity' => 5],
            ['ingredient_id' => 1, 'quantity' => 3],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertDatabaseCount('pos_restock_requests', 0); // unique (request, ingredient) → rolled back
    }
}
