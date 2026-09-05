<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\Product;
use App\Models\QrOrderRound;
use App\Models\Table as PosTable;
use App\Models\TableSession;
use Illuminate\Support\Str;

/** Explicit SQLite fixtures shared by the T4 feature tests. */
trait TableSessionFixtures
{
    protected function seatingBranch(int $id = 10, int $companyId = 100): Branch
    {
        return Branch::query()->firstOrCreate(['id' => $id], [
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
            'name' => 'Seating branch '.$id, 'status' => 'active',
            'latitude' => null, 'longitude' => null, 'geofence_radius_m' => 500,
        ]);
    }

    protected function seatingDevice(
        string $type = 'fixed_pos',
        int $branchId = 10,
        int $companyId = 100,
        array $attributes = [],
    ): Device {
        $this->seatingBranch($branchId, $companyId);

        return Device::factory()->paired()->create(array_replace([
            'company_id' => $companyId, 'branch_id' => $branchId, 'device_type' => $type,
        ], $attributes));
    }

    protected function seatingTable(
        string $label = 'Table 5',
        int $branchId = 10,
        int $companyId = 100,
        array $attributes = [],
    ): PosTable {
        $this->seatingBranch($branchId, $companyId);
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId,
            'name' => 'Seating floor', 'display_order' => 1, 'status' => 'active',
        ]);

        return PosTable::query()->create(array_replace([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'floor_id' => $floor->id,
            'label' => $label, 'seats' => 4, 'shape' => 'square',
            'qr_token' => hash('sha256', (string) Str::uuid()), 'status' => 'active', 'display_order' => 1,
        ], $attributes));
    }

    protected function seatingRow(PosTable $table, array $attributes = []): TableSession
    {
        $floor = Floor::withTrashed()->findOrFail($table->floor_id);

        return TableSession::query()->create(array_replace([
            'uuid' => (string) Str::uuid(), 'company_id' => $table->company_id,
            'branch_id' => $floor->branch_id, 'table_id' => $table->id,
            'origin' => TableSession::ORIGIN_STAFF_TILL, 'status' => TableSession::STATUS_OPEN,
            'client_request_id' => (string) Str::uuid(), 'temp_reference' => 'T-0905-001',
            'opened_at' => now(), 'expires_at' => now()->addHours(6),
        ], $attributes));
    }

    protected function seatingOrder(TableSession $seating, array $attributes = []): Order
    {
        $order = Order::query()->create(array_replace([
            'uuid' => (string) Str::uuid(), 'company_id' => $seating->company_id,
            'branch_id' => $seating->branch_id, 'device_id' => $seating->opened_by_device_id,
            'table_id' => $seating->table_id, 'table_session_id' => $seating->id,
            'order_type' => 'dine_in', 'status' => Order::STATUS_OPEN, 'source' => 'main_pos',
            'temp_reference' => $seating->temp_reference, 'subtotal' => '1.000',
            'discount_total' => '0.000', 'comp_total' => '0.000', 'tax_total' => '0.000',
            'grand_total' => '1.000', 'opened_at' => now(),
        ], $attributes));
        $seating->update(['order_id' => $order->id]);

        return $order;
    }

    protected function seatingRound(
        TableSession $seating,
        ?Order $order = null,
        array $attributes = [],
    ): QrOrderRound {
        return QrOrderRound::query()->create(array_replace([
            'qr_session_id' => null, 'table_session_id' => $seating->id,
            'order_id' => $order?->id, 'round_no' => 1,
            'status' => QrOrderRound::STATUS_ACCEPTED, 'client_request_id' => (string) Str::uuid(),
            'priced_lines' => [['line_total_baisas' => 1000]], 'subtotal_baisas' => 1000,
            'tax_baisas' => 0, 'total_baisas' => 1000, 'submitted_at' => now(), 'resolved_at' => now(),
        ], $attributes));
    }

    protected function seatingProduct(int $companyId = 100): Product
    {
        return Product::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
            'name' => 'Seating coffee', 'base_price' => '1.000',
            'tax_rate' => '0.00', 'stock_mode' => 'untracked', 'status' => 'active',
        ]);
    }
}
