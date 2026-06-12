<?php

declare(strict_types=1);

namespace App\Actions\Device\Production;

use App\Actions\Device\VerifyManagerPinAction;
use App\Models\BranchStock;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\PosStaff;
use App\Models\Production;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G1 — CANCEL an in-progress kitchen production batch (manager-gated).
 *
 * The manager's PIN is verified SERVER-SIDE against the company's
 * manager_approval_positions policy ({@see VerifyManagerPinAction} — the
 * exact gate the comp/void flows use); the approver is recorded on the
 * batch. Every production line (std + extra) returns to the branch shelf:
 * one signed positive 'production_return' pos_stock_movements row per line
 * + the pos_branch_stock balance move, atomically.
 *
 * InvalidPinException-equivalent: VerifyManagerPinAction throws
 * RuntimeException('Invalid PIN.') — the controller maps it to the same
 * generic 401 invalid_pin the verify endpoint uses. Other guard failures
 * throw RuntimeException too (mapped to 422).
 */
final readonly class CancelProductionAction
{
    public function __construct(
        private VerifyManagerPinAction $verifyManagerPin,
    ) {}

    public function handle(Device $device, string $uuid, string $pin, ?int $staffId): Production
    {
        $companyId = (int) $device->company_id;
        $branchId = (int) $device->branch_id;

        // PIN first: no point locking rows for an unauthorized request.
        // Throws RuntimeException('Invalid PIN.') on failure.
        $approver = $this->verifyManagerPin->verify($device, $pin);

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

        return DB::transaction(function () use ($uuid, $staffId, $approver, $companyId, $branchId): Production {
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

            $now = now();
            $lines = $production->lines()->orderBy('ingredient_id')->get();

            $costs = Ingredient::query()
                ->whereIn('id', $lines->pluck('ingredient_id')->all() ?: [0])
                ->pluck('default_unit_cost', 'id');

            foreach ($lines as $line) {
                $ingredientId = (int) $line->ingredient_id;
                $qty = (float) $line->quantity;

                StockMovement::create([
                    'branch_id' => $branchId,
                    'ingredient_id' => $ingredientId,
                    'movement_type' => StockMovement::TYPE_PRODUCTION_RETURN,
                    'quantity' => number_format($qty, 3, '.', ''),
                    'unit_cost_at_time' => number_format((float) ($costs[$ingredientId] ?? 0), 3, '.', ''),
                    'reference_type' => 'pos_productions',
                    'reference_id' => (int) $production->id,
                    'recorded_by_pos_staff_id' => $staffId,
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);

                $stock = BranchStock::query()
                    ->where('branch_id', $branchId)
                    ->where('ingredient_id', $ingredientId)
                    ->lockForUpdate()
                    ->first() ?? new BranchStock([
                        'branch_id' => $branchId,
                        'ingredient_id' => $ingredientId,
                    ]);
                $stock->quantity = (float) $stock->quantity + $qty;
                $stock->last_movement_at = $now;
                $stock->save();
            }

            $production->update([
                'status' => Production::STATUS_CANCELLED,
                'cancelled_by_staff_id' => $staffId,
                'cancel_approved_by_staff_id' => (int) $approver->id,
                'cancelled_at' => $now,
            ]);

            return $production->refresh();
        });
    }
}
