<?php

declare(strict_types=1);

namespace App\Actions\Device\Production;

use App\Models\BranchProduct;
use App\Models\Device;
use App\Models\PosStaff;
use App\Models\Production;
use App\Models\ProductStockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G1 — FINISH a kitchen production batch (phase 2 of 2).
 *
 * Stamps finished_at + the recorded duration, and lands the produced
 * pieces in the branch's shelf stock: pos_branch_product.stock_qty +=
 * quantity (row created at zero if missing — NULL stock_qty promotes to
 * 0, the merchant WriteProductStockMovementAction convention) plus a
 * signed 'produced' row in the PRODUCT ledger so the batch shows up in
 * the merchant Stock dialog history.
 *
 * The caller broadcasts after commit so every till's tile un-greys via
 * the live config-delta refresh (stock 0 -> N wakes the product up).
 *
 * Throws RuntimeException with a user-facing message on guard failure.
 */
final readonly class FinishProductionAction
{
    public function handle(Device $device, string $uuid, ?int $staffId): Production
    {
        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;

        if ($staffId !== null) {
            $staffOk = PosStaff::query()
                ->where('company_id', $companyId)
                ->where('status', PosStaff::STATUS_ACTIVE)
                ->whereKey($staffId)
                ->exists();
            if (! $staffOk) {
                throw new RuntimeException('Unknown staff member.');
            }
        }

        return DB::transaction(function () use ($uuid, $staffId, $companyId, $branchId): Production {
            $production = Production::query()
                ->where('company_id', $companyId)
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->first();

            if ($production === null || (int) $production->branch_id !== $branchId) {
                throw new RuntimeException('Unknown production batch.');
            }
            if ($production->status !== Production::STATUS_IN_PROGRESS) {
                throw new RuntimeException('This batch is no longer in progress.');
            }

            $qty = (float) $production->quantity;
            $now = now();

            $row = BranchProduct::query()
                ->where('branch_id', $branchId)
                ->where('product_id', (int) $production->product_id)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                $row = new BranchProduct([
                    'branch_id' => $branchId,
                    'product_id' => (int) $production->product_id,
                    'is_available' => true,
                ]);
            }
            $row->stock_qty = (float) ($row->stock_qty ?? 0) + $qty;
            $row->save();

            ProductStockMovement::create([
                'company_id' => $companyId,
                'product_id' => (int) $production->product_id,
                'branch_id' => $branchId,
                'movement_type' => ProductStockMovement::TYPE_PRODUCED,
                'quantity' => number_format($qty, 3, '.', ''),
                'reference_type' => 'pos_productions',
                'reference_id' => (int) $production->id,
                'recorded_by_pos_staff_id' => $staffId,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            $production->update([
                'status' => Production::STATUS_FINISHED,
                'finished_by_staff_id' => $staffId,
                'finished_at' => $now,
                // Recorded (not derived) per spec — the kitchen batch-duration
                // statistic the merchant reports on.
                'duration_seconds' => max(0, (int) $production->started_at->diffInSeconds($now)),
            ]);

            return $production->refresh();
        });
    }
}
