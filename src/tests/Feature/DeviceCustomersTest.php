<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8.7 — device customer lookup + registration.
 *   GET  /api/v1/device/customers/search?q=
 *   GET  /api/v1/device/customers/{id}      (P-F2 details fetch)
 *   POST /api/v1/device/customers
 *
 * P-F2 — plates are many-to-many: one customer ↔ many plates AND one
 * plate ↔ many customers (family car shared by several loyalty members).
 */
class DeviceCustomersTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_cust', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Ali Said',
            'phone' => '+96890001234',
            'wallet_balance' => '3.000',
        ], $overrides));
    }

    public function test_search_finds_by_phone(): void
    {
        $this->device();
        $this->customer(['phone' => '+96890001234']);

        $res = $this->withToken('mdev_cust')->getJson('/api/v1/device/customers/search?q=0001234')->assertOk();
        $this->assertCount(1, $res->json('data.customers'));
        $this->assertSame(3000, $res->json('data.customers.0.wallet_balance_baisas'));
    }

    public function test_search_returns_live_loyalty_balances(): void
    {
        $this->device();
        $c = $this->customer(['phone' => '+96890001234']);
        DB::table('pos_loyalty_rules')->insert([
            'id' => 7, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Points',
            'type' => 'spend_based', 'config_json' => json_encode(['points_per_omr' => 10]),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_loyalty_accounts')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'customer_id' => $c->id,
            'loyalty_rule_id' => 7, 'point_balance' => 240, 'stamp_count' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->withToken('mdev_cust')->getJson('/api/v1/device/customers/search?q=0001234')->assertOk();
        $loyalty = $res->json('data.customers.0.loyalty');
        $this->assertCount(1, $loyalty);
        $this->assertSame(7, $loyalty[0]['rule_id']);
        $this->assertSame(240, $loyalty[0]['points']);
        $this->assertSame(3, $loyalty[0]['stamps']);
    }

    public function test_search_finds_by_name_case_insensitively(): void
    {
        $this->device();
        $this->customer(['name' => 'Ali Said']);

        $res = $this->withToken('mdev_cust')->getJson('/api/v1/device/customers/search?q=ali')->assertOk();
        $this->assertCount(1, $res->json('data.customers'));
    }

    public function test_search_finds_by_vehicle_plate(): void
    {
        $this->device();
        $c = $this->customer();
        CustomerVehiclePlate::create(['uuid' => (string) Str::uuid(), 'customer_id' => $c->id, 'company_id' => 100, 'plate_number' => '12345 A']);

        $res = $this->withToken('mdev_cust')->getJson('/api/v1/device/customers/search?q=12345')->assertOk();
        $this->assertCount(1, $res->json('data.customers'));
        $this->assertSame(['12345 A'], $res->json('data.customers.0.plates'));
    }

    public function test_search_is_company_scoped(): void
    {
        $this->device(); // company 100
        $this->customer(['company_id' => 200, 'name' => 'Other Co Customer', 'phone' => '+96899999999']);

        $res = $this->withToken('mdev_cust')->getJson('/api/v1/device/customers/search?q=999')->assertOk();
        $this->assertCount(0, $res->json('data.customers'));
    }

    public function test_search_requires_a_query(): void
    {
        $this->device();
        $this->withToken('mdev_cust')->getJson('/api/v1/device/customers/search')
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_store_creates_a_customer(): void
    {
        $this->device();

        $res = $this->withToken('mdev_cust')->postJson('/api/v1/device/customers', [
            'name' => 'Sara', 'phone' => '+96890005678',
        ])->assertOk();

        $this->assertSame('Sara', $res->json('data.customer.name'));
        $this->assertDatabaseHas('pos_customers', ['company_id' => 100, 'phone' => '+96890005678', 'name' => 'Sara']);
    }

    public function test_store_find_or_creates_on_phone(): void
    {
        $this->device();
        $existing = $this->customer(['phone' => '+96890001234', 'name' => 'Ali']);

        $res = $this->withToken('mdev_cust')->postJson('/api/v1/device/customers', [
            'name' => 'Ignored Name', 'phone' => '+96890001234',
        ])->assertOk();

        // Same customer returned; no duplicate row.
        $this->assertSame($existing->id, $res->json('data.customer.id'));
        $this->assertSame(1, DB::table('pos_customers')->where('phone', '+96890001234')->count());
    }

    public function test_store_registers_a_vehicle_plate(): void
    {
        $this->device();

        $this->withToken('mdev_cust')->postJson('/api/v1/device/customers', [
            'name' => 'Driver', 'phone' => '+96890007777', 'plate_number' => '99 xyz',
        ])->assertOk();

        // Stored uppercased.
        $this->assertDatabaseHas('pos_customer_vehicle_plates', ['company_id' => 100, 'plate_number' => '99 XYZ']);
    }

    public function test_store_attaches_an_already_owned_plate_to_the_new_customer_too(): void
    {
        // P-F2 many-to-many: the plate already belongs to ANOTHER customer —
        // registering a second customer with the same plate ADDS a link
        // instead of silently leaving the plate with the first owner.
        $this->device();
        $first = $this->customer(['phone' => '+96890001234']);
        CustomerVehiclePlate::create(['uuid' => (string) Str::uuid(), 'customer_id' => $first->id, 'company_id' => 100, 'plate_number' => '12345 A']);

        $res = $this->withToken('mdev_cust')->postJson('/api/v1/device/customers', [
            'name' => 'Second Driver', 'phone' => '+96890005678', 'plate_number' => '12345 a',
        ])->assertOk();

        // The new customer's mapped response carries the plate...
        $this->assertSame(['12345 A'], $res->json('data.customer.plates'));

        // ...and BOTH links exist (the original owner kept theirs).
        $secondId = $res->json('data.customer.id');
        $this->assertDatabaseHas('pos_customer_vehicle_plates', ['company_id' => 100, 'customer_id' => $first->id, 'plate_number' => '12345 A']);
        $this->assertDatabaseHas('pos_customer_vehicle_plates', ['company_id' => 100, 'customer_id' => $secondId, 'plate_number' => '12345 A']);
        $this->assertSame(2, DB::table('pos_customer_vehicle_plates')->where('plate_number', '12345 A')->count());
    }

    public function test_store_reposting_the_same_customer_plate_does_not_duplicate_the_link(): void
    {
        $this->device();

        foreach (['99 XYZ', '99 xyz'] as $variant) {
            $this->withToken('mdev_cust')->postJson('/api/v1/device/customers', [
                'name' => 'Driver', 'phone' => '+96890007777', 'plate_number' => $variant,
            ])->assertOk();
        }

        // One customer, ONE link — firstOrCreate on (company, customer,
        // plate) makes the re-post idempotent.
        $this->assertSame(1, DB::table('pos_customer_vehicle_plates')->where('plate_number', '99 XYZ')->count());
    }

    public function test_store_plate_attach_touches_the_customer_so_the_delta_sync_sees_it(): void
    {
        $this->device();
        $old = now()->subDay();
        $c = $this->customer(['phone' => '+96890001234']);
        DB::table('pos_customers')->where('id', $c->id)->update([
            'created_at' => $old->toDateTimeString(), 'updated_at' => $old->toDateTimeString(),
        ]);

        $this->withToken('mdev_cust')->postJson('/api/v1/device/customers', [
            'name' => 'Ali', 'phone' => '+96890001234', 'plate_number' => '777 T',
        ])->assertOk();

        // The customer row was touched (updated_at moved past the old stamp)...
        $this->assertTrue(
            Customer::query()->findOrFail($c->id)->updated_at->greaterThan($old->addHours(12)),
        );

        // ...so a /device/config/delta from before the attach picks the
        // customer up — plates included — and OTHER devices learn the plate.
        $since = now()->subHour()->toIso8601String();
        $delta = $this->withToken('mdev_cust')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($since))
            ->assertOk()->json('data');
        $this->assertSame([$c->id], collect($delta['customers'])->pluck('id')->all());
        $this->assertSame(['777 T'], $delta['customers'][0]['plates']);
    }

    public function test_show_returns_the_mapped_customer_with_plates_and_loyalty(): void
    {
        $this->device();
        $c = $this->customer(['phone' => '+96890001234']);
        CustomerVehiclePlate::create(['uuid' => (string) Str::uuid(), 'customer_id' => $c->id, 'company_id' => 100, 'plate_number' => '12345 A']);
        DB::table('pos_loyalty_rules')->insert([
            'id' => 7, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Points',
            'type' => 'spend_based', 'config_json' => json_encode(['points_per_omr' => 10]),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_loyalty_accounts')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'customer_id' => $c->id,
            'loyalty_rule_id' => 7, 'point_balance' => 240, 'stamp_count' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->withToken('mdev_cust')->getJson('/api/v1/device/customers/'.$c->id)->assertOk();

        // Same envelope + shape as search's mapCustomer.
        $this->assertSame('baisas', $res->json('meta.money_unit'));
        $this->assertSame([], $res->json('errors'));
        $customer = $res->json('data.customer');
        $this->assertSame($c->id, $customer['id']);
        $this->assertSame($c->uuid, $customer['uuid']);
        $this->assertSame('Ali Said', $customer['name']);
        $this->assertSame('+96890001234', $customer['phone']);
        $this->assertSame(3000, $customer['wallet_balance_baisas']);
        $this->assertSame(['12345 A'], $customer['plates']);
        $this->assertSame([['rule_id' => 7, 'points' => 240, 'stamps' => 3]], $customer['loyalty']);
    }

    public function test_show_404s_on_another_companys_customer(): void
    {
        $this->device(); // company 100
        $foreign = $this->customer(['company_id' => 200, 'phone' => '+96899999999']);

        $res = $this->withToken('mdev_cust')
            ->getJson('/api/v1/device/customers/'.$foreign->id)
            ->assertStatus(404);
        $this->assertSame('customer_not_found', $res->json('errors.0.code'));
        $this->assertNull($res->json('data'));
    }

    public function test_show_requires_a_device_token(): void
    {
        $this->device();
        $c = $this->customer();
        $this->getJson('/api/v1/device/customers/'.$c->id)->assertStatus(401);
    }

    public function test_show_rejects_a_blocked_device_token(): void
    {
        // Lifecycle revocation — same contract as the sibling endpoints.
        Device::factory()->paired('mdev_blocked')->create(['company_id' => 100, 'branch_id' => 10, 'status' => 'blocked']);
        $c = $this->customer();
        $this->withToken('mdev_blocked')->getJson('/api/v1/device/customers/'.$c->id)->assertStatus(401);
    }

    public function test_store_requires_name_and_phone(): void
    {
        $this->device();
        $this->withToken('mdev_cust')->postJson('/api/v1/device/customers', [])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'phone']);
    }

    public function test_an_unassigned_device_is_rejected(): void
    {
        Device::factory()->paired('mdev_unassigned')->create(['company_id' => null, 'branch_id' => null]);
        $this->withToken('mdev_unassigned')->getJson('/api/v1/device/customers/search?q=ali')->assertStatus(409);
    }

    public function test_store_revives_a_soft_deleted_customer_instead_of_500ing(): void
    {
        // Regression: a soft-deleted customer still OCCUPIES the
        // (company_id, phone) unique slot, so the old find-or-create path
        // (which skips trashed rows) crashed into the index with a 500 the
        // moment a cashier re-registered a deleted customer's phone.
        $this->device();
        $old = $this->customer(['name' => 'Ali Said', 'phone' => '+96890007777']);
        $old->delete();
        $this->assertSoftDeleted('pos_customers', ['id' => $old->id]);

        $res = $this->withToken('mdev_cust')->postJson('/api/v1/device/customers', [
            'name' => 'Ali S. (returning)',
            'phone' => '+96890007777',
        ]);

        $res->assertOk();
        $this->assertSame($old->id, $res->json('data.customer.id')); // revived, not duplicated
        $this->assertSame('Ali S. (returning)', $res->json('data.customer.name'));

        $revived = Customer::query()->findOrFail($old->id);
        $this->assertNull($revived->deleted_at);
        $this->assertSame('Ali S. (returning)', $revived->name);
        $this->assertSame(1, Customer::query()->withTrashed()->where('phone', '+96890007777')->count());
    }

    public function test_store_revive_still_attaches_a_plate(): void
    {
        // The revive path must flow into the same plate attach the normal
        // find-or-create does (drive-up capture on a returning customer).
        $this->device();
        $old = $this->customer(['phone' => '+96890008888']);
        $old->delete();

        $res = $this->withToken('mdev_cust')->postJson('/api/v1/device/customers', [
            'name' => 'Back Again',
            'phone' => '+96890008888',
            'plate_number' => '  9876  b ',
        ]);

        $res->assertOk();
        // plates[] is a flat list of canonical plate strings.
        $this->assertSame(['9876 B'], $res->json('data.customer.plates'));
        $this->assertDatabaseHas('pos_customer_vehicle_plates', [
            'customer_id' => $old->id,
            'plate_number' => '9876 B',
        ]);
    }
}
