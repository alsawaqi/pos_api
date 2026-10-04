<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Device\VerifyManagerPinAction;
use App\Models\Device;
use App\Models\PosStaff;
use App\Models\Product;
use App\Support\Catalogue\BranchCatalogue;
use App\Support\Staff\AuthorizationGate;
use App\Support\Staff\AuthorizationOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P4 A5 — the manual "sold out" switch from a device (owner decision 4).
 *
 *   GET  /api/v1/device/sold-out
 *        → {product_ids: [...], as_of} for the device's branch. Devices poll
 *          it every 60 s while online and on resume.
 *   POST /api/v1/device/products/{id}/sold-out
 *        {sold_out: bool, staff_id, auth_v: 1, authorization}   (P5 build)
 *        {sold_out: bool, staff_id, approver_staff_id?}         (old build)
 *
 * LAUNCH-P5: a P5 build's authorization block decides (sold_out.toggle: the
 * actor's own tick, or a verified approver), else 403 approval_required /
 * approval_invalid; every call writes a pos_approvals row. An old build keeps
 * today's rule: an active staff member whose position is manager or
 * supervisor; anyone else sends an approver_staff_id whose position holds
 * approvals.give (recorded as `legacy`). The switch applies to this device's branch
 * only, on every channel, until switched back; it is never driven by stock.
 * Each change writes or deletes the pos_product_sold_out row, bumps the
 * product so config deltas re-emit it, and is audited.
 */
final class DeviceSoldOutController
{
    /** Positions that may switch sold out without an approver. */
    public const SELF_POSITIONS = ['manager', 'supervisor'];

    public function index(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        if (! $device->isAssigned()) {
            return $this->failure('device_unassigned', 'This device is not assigned to a branch.', 409);
        }

        $ids = array_keys(BranchCatalogue::soldOutAt((int) $device->branch_id));
        sort($ids);

        return response()->json(['data' => ['product_ids' => $ids, 'as_of' => now()->toIso8601String()], 'errors' => []]);
    }

    public function update(Request $request, int $productId, VerifyManagerPinAction $pins, AuthorizationGate $gate): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        if (! $device->isAssigned()) {
            return $this->failure('device_unassigned', 'This device is not assigned to a branch.', 409);
        }
        $data = $request->validate([
            'sold_out' => ['required', 'boolean'],
            'staff_id' => ['required', 'integer', 'min:1'],
            'approver_staff_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'auth_v' => ['sometimes', 'integer'],
            'authorization' => ['sometimes', 'nullable', 'array'],
        ]);

        $product = Product::query()->where('company_id', $device->company_id)->find($productId);
        if ($product === null) {
            return $this->failure('product_not_found', 'The product was not found.', 404);
        }
        $staff = $this->activeStaff($device, (int) $data['staff_id']);
        if ($staff === null) {
            return $this->failure('unknown_staff', 'Unknown staff member.', 422);
        }
        $approverId = isset($data['approver_staff_id']) ? (int) $data['approver_staff_id'] : null;
        $authorization = null;
        if (AuthorizationGate::isP5($request->all())) {
            // LAUNCH-P5 — sold_out.toggle: the actor's own tick (position block)
            // or a verified approver; anything else is refused (403). Proof
            // subject: the product uuid; no amount; the block's ref.
            $outcome = DB::transaction(fn () => $gate->evaluate($device, [
                'action' => 'sold_out.toggle', 'subject_type' => 'product', 'subject_uuid' => (string) $product->uuid,
                'actor_staff_id' => (int) $staff->id, 'client_event_id' => null, 'at' => now(),
            ], AuthorizationGate::block($request->input('authorization')), true));
            if (! $outcome->authorized()) {
                return $this->failure($outcome->refusalCode(), $outcome->refusalCode() === 'approval_required'
                    ? 'A manager must approve this change.' : 'The manager approval could not be verified. Approve again.', 403);
            }
            $approverId = $outcome->result === AuthorizationOutcome::VERIFIED ? $outcome->approverStaffId : null;
            $authorization = ['action' => 'sold_out.toggle', 'result' => $outcome->result];
        } elseif (! in_array((string) $staff->position, self::SELF_POSITIONS, true)) {
            // An old build: today's rule (manager / supervisor alone, others
            // with an approver of an approval position), recorded as legacy.
            $approver = $approverId === null ? null : $this->activeStaff($device, $approverId);
            if ($approver === null || ! in_array((string) $approver->position, $pins->approvalPositions((int) $device->company_id), true)) {
                return $this->failure('approval_required', 'A manager must approve this change.', 403);
            }
        } else {
            $approverId = null;
        }
        if ($authorization === null) {
            $gate->evaluate($device, [
                'action' => 'sold_out.toggle', 'subject_type' => 'product', 'subject_uuid' => (string) $product->uuid,
                'actor_staff_id' => (int) $staff->id, 'client_event_id' => null, 'at' => now(),
                'legacy_approver_staff_id' => $approverId,
            ], null, false);
        }

        $soldOut = (bool) $data['sold_out'];
        $setBy = $approverId ?? (int) $staff->id;
        $changed = DB::transaction(function () use ($device, $product, $soldOut, $setBy, $staff, $approverId): bool {
            $now = now();
            $existing = DB::table('pos_product_sold_out')->where('branch_id', $device->branch_id)
                ->where('product_id', $product->id)->lockForUpdate()->first();
            if ($soldOut === ($existing !== null)) {
                return false;
            }
            if ($soldOut) {
                DB::table('pos_product_sold_out')->insert([
                    'company_id' => $device->company_id, 'branch_id' => $device->branch_id, 'product_id' => $product->id,
                    'set_by_user_id' => null, 'set_by_pos_staff_id' => $setBy, 'set_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                DB::table('pos_product_sold_out')->where('id', $existing->id)->delete();
            }
            // Re-emit the product in config deltas (a deleted row leaves no trace).
            DB::table('pos_products')->where('id', $product->id)->update(['updated_at' => $now]);
            DB::table('pos_audit_logs')->insert([
                'actor_user_id' => null, 'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
                'event' => $soldOut ? 'product.sold_out.set' : 'product.sold_out.cleared',
                'auditable_type' => 'App\\Models\\Product', 'auditable_id' => $product->id,
                'metadata' => json_encode(['device_id' => (int) $device->id, 'staff_id' => (int) $staff->id,
                    'approver_staff_id' => $approverId, 'source' => 'device']),
                'created_at' => $now, // pos_audit_logs has no updated_at
            ]);

            return true;
        });

        return response()->json(['data' => [
            'product_id' => (int) $product->id,
            'sold_out' => $soldOut,
            'changed' => $changed,
            'as_of' => now()->toIso8601String(),
        ] + ($authorization === null ? [] : ['authorization' => $authorization]), 'errors' => []]);
    }

    private function activeStaff(Device $device, int $staffId): ?PosStaff
    {
        return PosStaff::query()->where('company_id', $device->company_id)
            ->where('status', PosStaff::STATUS_ACTIVE)->whereKey($staffId)->first();
    }

    private function failure(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['data' => null, 'errors' => [['code' => $code, 'message' => $message]]], $status);
    }
}
