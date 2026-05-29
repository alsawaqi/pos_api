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
 *   POST /api/v1/device/customers
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
}
