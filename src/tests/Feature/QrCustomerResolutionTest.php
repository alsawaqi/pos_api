<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ResolveQrCustomerAction;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QrCustomerResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_phone_is_created_and_later_reused_without_public_name_input(): void
    {
        $action = app(ResolveQrCustomerAction::class);

        $first = DB::transaction(fn () => $action->handle(100, '90001234', null));
        $second = DB::transaction(fn () => $action->handle(100, '90001234', null));

        $this->assertSame($first->customerId, $second->customerId);
        $this->assertDatabaseCount('pos_customers', 1);
        $this->assertDatabaseHas('pos_customers', [
            'id' => $first->customerId,
            'company_id' => 100,
            'phone' => '90001234',
            'name' => '90001234',
        ]);
    }

    public function test_soft_deleted_customer_is_restored_without_renaming_or_losing_history(): void
    {
        $customer = Customer::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Staff Authored Name',
            'phone' => '91112222',
        ]);
        DB::table('pos_customer_vehicle_plates')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'customer_id' => $customer->id,
            'plate_number' => 'OLD 7',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pos_loyalty_rules')->insert([
            'id' => 51,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Points',
            'type' => 'spend_based',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pos_loyalty_accounts')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'customer_id' => $customer->id,
            'loyalty_rule_id' => 51,
            'point_balance' => 44,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer->delete();

        $resolved = DB::transaction(
            fn () => app(ResolveQrCustomerAction::class)->handle(100, '91112222', null),
        );

        $this->assertSame((int) $customer->id, $resolved->customerId);
        $this->assertDatabaseHas('pos_customers', [
            'id' => $customer->id,
            'name' => 'Staff Authored Name',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('pos_customer_vehicle_plates', [
            'customer_id' => $customer->id,
            'plate_number' => 'OLD 7',
        ]);
        $this->assertDatabaseHas('pos_loyalty_accounts', [
            'customer_id' => $customer->id,
            'point_balance' => 44,
        ]);
    }

    public function test_plate_normalisation_is_idempotent_and_allows_a_shared_family_car(): void
    {
        $action = app(ResolveQrCustomerAction::class);

        $first = DB::transaction(fn () => $action->handle(100, '90000001', '  12345   a  '));
        DB::transaction(fn () => $action->handle(100, '90000001', '12345 A'));
        DB::transaction(fn () => $action->handle(100, '90000001', '12345-A'));
        $second = DB::transaction(fn () => $action->handle(100, '90000002', '12345 A'));

        $this->assertSame('12345 A', $first->plateNumber);
        $this->assertSame('12345 A', $second->plateNumber);
        $this->assertSame(2, DB::table('pos_customer_vehicle_plates')
            ->where('customer_id', $first->customerId)
            ->count());
        $this->assertSame(2, DB::table('pos_customer_vehicle_plates')
            ->where('plate_number', '12345 A')
            ->count());
        $this->assertDatabaseHas('pos_customer_vehicle_plates', [
            'customer_id' => $first->customerId,
            'plate_number' => '12345-A',
        ]);
    }
}
