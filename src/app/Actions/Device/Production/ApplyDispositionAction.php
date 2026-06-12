<?php

declare(strict_types=1);

namespace App\Actions\Device\Production;

use App\Actions\Device\VerifyManagerPinAction;
use App\Models\BranchProduct;
use App\Models\Device;
use App\Models\PosStaff;
use App\Models\Product;
use App\Models\ProductStockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G1.5 — apply the day-end disposition the closer chose for expired
 * cooked pieces. Per item the remainder splits across:
 *
 *   waste       negative 'waste' ledger row, optional comment;
 *   give_away   negative 'give_away' ledger row — MANAGER-APPROVED with a
 *               REQUIRED comment naming the recipient (staff meal /
 *               customer). Distinct from waste on the P&L;
 *   carry_over  nothing moves, but a quantity-0 AUDIT row records the
 *               manager-approved decision to keep expired pieces on sale.
 *
 * One PIN approves the whole submission (the manager stands at the till
 * once) — verified server-side via {@see VerifyManagerPinAction}, and
 * only demanded when a gift or carry-over is present. Waste alone needs
 * no approval (mirrors the ingredient waste flow).
 *
 * Unlike sales, disposition can NEVER drive the shelf negative — each
 * product's row is locked and the removed quantity is capped by it.
 */
final readonly class ApplyDispositionAction
{
    public function __construct(
        private VerifyManagerPinAction $verifyManagerPin,
    ) {}

    /**
     * @param  list<array{product_id: int, waste_qty?: float|int|string, give_away_qty?: float|int|string, carry_over_qty?: float|int|string, comment?: string|null, give_away_comment?: string|null}>  $items
     * @return array{moved: int, audited: int}
     */
    public function handle(Device $device, array $items, ?string $pin, ?int $staffId): array
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

        $needsApproval = false;
        foreach ($items as $item) {
            if ((float) ($item['give_away_qty'] ?? 0) > 0 || (float) ($item['carry_over_qty'] ?? 0) > 0) {
                $needsApproval = true;
                if ((float) ($item['give_away_qty'] ?? 0) > 0 && trim((string) ($item['give_away_comment'] ?? '')) === '') {
                    throw new RuntimeException('A comment naming the recipient is required for a give-away.');
                }
            }
        }

        $approver = null;
        if ($needsApproval) {
            if ($pin === null || $pin === '') {
                throw new RuntimeException('Invalid PIN.');
            }
            // Throws RuntimeException('Invalid PIN.') on failure.
            $approver = $this->verifyManagerPin->verify($device, $pin);
        }

        return DB::transaction(function () use ($items, $approver, $staffId, $companyId, $branchId): array {
            $now = now();
            $moved = 0;
            $audited = 0;

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $waste = (float) ($item['waste_qty'] ?? 0);
                $gift = (float) ($item['give_away_qty'] ?? 0);
                $carry = (float) ($item['carry_over_qty'] ?? 0);

                if ($waste <= 0 && $gift <= 0 && $carry <= 0) {
                    continue;
                }

                $product = Product::query()
                    ->where('company_id', $companyId)
                    ->find($productId);
                if ($product === null) {
                    throw new RuntimeException('Unknown product.');
                }

                $row = BranchProduct::query()
                    ->where('branch_id', $branchId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();
                $available = $row?->stock_qty !== null ? (float) $row->stock_qty : 0.0;

                if ($waste + $gift > $available + 1e-9) {
                    throw new RuntimeException(sprintf(
                        'Cannot remove %s of %s: only %s on the shelf.',
                        rtrim(rtrim(number_format($waste + $gift, 3, '.', ''), '0'), '.'),
                        $product->name,
                        rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.'),
                    ));
                }

                $approvedBy = $approver !== null ? sprintf('[approved by %s] ', $approver->name) : '';

                if ($waste > 0) {
                    $this->ledger($companyId, $productId, $branchId, ProductStockMovement::TYPE_WASTE, -$waste, $staffId, trim((string) ($item['comment'] ?? '')) ?: 'day-end disposition', $now);
                    $moved++;
                }
                if ($gift > 0) {
                    $this->ledger($companyId, $productId, $branchId, ProductStockMovement::TYPE_GIVE_AWAY, -$gift, $staffId, $approvedBy.trim((string) $item['give_away_comment']), $now);
                    $moved++;
                }
                if ($carry > 0) {
                    $this->ledger($companyId, $productId, $branchId, ProductStockMovement::TYPE_CARRY_OVER, 0.0, $staffId, $approvedBy.sprintf('carried over %s expired pieces', rtrim(rtrim(number_format($carry, 3, '.', ''), '0'), '.')), $now);
                    $audited++;
                }

                if (($waste > 0 || $gift > 0) && $row !== null) {
                    $row->stock_qty = $available - $waste - $gift;
                    $row->save();
                }
            }

            return ['moved' => $moved, 'audited' => $audited];
        });
    }

    private function ledger(int $companyId, int $productId, int $branchId, string $type, float $qty, ?int $staffId, string $note, \Illuminate\Support\Carbon $at): void
    {
        ProductStockMovement::create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'branch_id' => $branchId,
            'movement_type' => $type,
            'quantity' => number_format($qty, 3, '.', ''),
            'recorded_by_pos_staff_id' => $staffId,
            'note' => $note,
            'occurred_at' => $at,
            'created_at' => $at,
        ]);
    }
}
