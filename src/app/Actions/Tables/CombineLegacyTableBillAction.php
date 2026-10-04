<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Device\VerifyManagerPinAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\PosStaff;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use App\Support\Pricing\BillMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit, online, manager-reviewed reconciliation. Never a board side effect. */
final class CombineLegacyTableBillAction
{
    public function __construct(
        private readonly TableReadSnapshot $read,
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly FrozenLegacyTableBill $frozen,
        private readonly VerifyManagerPinAction $managers,
        private readonly RefreshQrOrderTotalsAction $totals,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    public function preview(Device $device, int $tableId, string $sourceUuid): array
    {
        $this->attended($device);

        return $this->read->handle(function () use ($device, $tableId, $sourceUuid): array {
            $current = Device::query()->find($device->id);
            if ($current === null || (int) $current->company_id !== (int) $device->company_id
                || (int) $current->branch_id !== (int) $device->branch_id) {
                throw $this->refusal('device_not_attended', 'This device is no longer assigned to this branch.');
            }
            $this->attended($current);
            $state = $this->state($current, $tableId, $sourceUuid);
            $expires = now()->addMinutes(5)->timestamp;
            $source = $this->frozen->present($state['source_snapshot']);
            $target = $this->frozen->present($state['target_snapshot']);

            return ['table_id' => $tableId, 'table_label' => $state['table']->label,
                'source' => $source, 'target' => $target,
                'combine_policy' => 'local_owner_v1', 'table_session_uuid' => $state['seat']->uuid,
                'combined_grand_total_baisas' => $source['grand_total_baisas'] + $target['grand_total_baisas'],
                'preview_token' => $expires.'.'.$this->signature($current, $state, $expires),
                'expires_at' => now()->setTimestamp($expires)->toIso8601String(),
                'requires_manager_pin' => true, 'kitchen_submission' => false, 'reason' => 'same_party_duplicate_bill'];
        });
    }

    public function handle(Device $device, int $tableId, array $input): array
    {
        $this->attended($device);
        Validator::make($input, [
            'source_order_uuid' => ['required', 'uuid'], 'target_order_uuid' => ['required', 'uuid'],
            'client_request_id' => ['required', 'uuid'], 'preview_token' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'in:same_party_duplicate_bill'], 'pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
        ])->validate();
        // The expensive password check occurs before branch locks. Recheck the
        // exact staff/policy snapshot inside the transaction; never persist PIN.
        try {
            $manager = $this->managers->verify($device, $input['pin']);
        } catch (RuntimeException) {
            throw new QrDineInException('invalid_pin', 401, 'Invalid PIN.');
        }
        $policy = $this->policy($device);
        $requestHash = hash('sha256', json_encode([$tableId, $input['source_order_uuid'], $input['target_order_uuid'],
            $input['preview_token'], $input['reason']], JSON_THROW_ON_ERROR));
        $payload = ['table_id' => $tableId, 'seating_key' => (string) Str::uuid(), 'queued_offline' => false];

        return $this->resolver->locked($device, $payload, 'combine', function ($device) use ($tableId, $input, $manager, $policy, $requestHash): array {
            $currentManager = PosStaff::query()->find($manager->id);
            if ($currentManager === null || $currentManager->getRawOriginal() !== $manager->getRawOriginal()
                || $this->policy($device) !== $policy) {
                throw new QrDineInException('invalid_pin', 401, 'Manager authorization changed. Verify again.');
            }
            $previous = TableSessionEvent::query()->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)->where('device_id', $device->id)
                ->where('event_type', 'merged')->where('payload->action', 'legacy_bill_combined')
                ->where('payload->client_request_id', $input['client_request_id'])->first();
            if ($previous !== null) {
                if (! hash_equals($previous->payload['request_hash'], $requestHash)) {
                    throw $this->refusal('combine_request_conflict', 'This request id belongs to a different combine.');
                }

                return $previous->payload['result'] + ['event_id' => (int) $previous->id];
            }
            // A lost reply is replayed ABOVE before considering expiry. Once
            // this exact token has expired, neither a delayed original request
            // nor another retry can start a combine. Clients may then release
            // their durable intent without discarding the original local bill.
            $parts = explode('.', $input['preview_token']);
            if (count($parts) === 2 && ctype_digit($parts[0]) && (int) $parts[0] <= now()->timestamp) {
                throw new QrDineInException('combine_preview_stale', 409,
                    'The preview changed or expired. Review both bills again.', finalNoWrite: true);
            }
            $state = $this->state($device, $tableId, $input['source_order_uuid']);
            if ($state['target']->uuid !== $input['target_order_uuid']) {
                throw $this->refusal('combine_preview_stale', 'The table bill changed. Review both bills again.');
            }
            if (count($parts) !== 2 || ! ctype_digit($parts[0]) || (int) $parts[0] <= now()->timestamp
                || ! hash_equals($this->signature($device, $state, (int) $parts[0]), $parts[1])) {
                throw $this->refusal('combine_preview_stale', 'The preview changed or expired. Review both bills again.');
            }
            $source = $state['source'];
            $target = $state['target'];
            $copy = $this->frozen->copyTo($state['source_snapshot'], $target);
            $money = $this->frozen->totals($state['source_snapshot']);
            $round = QrOrderRound::query()->create([
                'qr_session_id' => null, 'table_session_id' => $state['seat']->id, 'order_id' => $target->id,
                'client_request_id' => 'combine:'.$input['client_request_id'],
                'round_no' => (int) QrOrderRound::query()->where('order_id', $target->id)->max('round_no') + 1,
                'status' => QrOrderRound::STATUS_ACCEPTED, 'priced_lines' => $copy['priced_lines'],
                'subtotal_baisas' => $money['subtotal_baisas'], 'tax_baisas' => $money['tax_total_baisas'],
                'total_baisas' => $money['grand_total_baisas'], 'submitted_at' => $source->opened_at,
                'resolved_at' => now(), 'resolved_by_device_id' => $device->id,
                // Reconciliation is not a new kitchen submission. No sequence,
                // no fabricated printed_at and no automatic duplicate ticket.
                'accepted_seq' => null, 'confirm_payload' => null, 'needs_review' => false,
            ]);
            $this->totals->handle($target);
            $source->update(['status' => Order::STATUS_COMBINED, 'closed_at' => now()]);
            $result = ['outcome' => 'combined', 'source_order_uuid' => $source->uuid,
                'source_status' => Order::STATUS_COMBINED, 'order_uuid' => $target->uuid,
                'table_session_uuid' => $state['seat']->uuid, 'temp_reference' => $target->temp_reference,
                'receipt_number' => $target->receipt_number, 'round_id' => (int) $round->id,
                'grand_total_baisas' => $this->frozen->totals(['order' => $target->getAttributes()])['grand_total_baisas'],
                'approved_by_staff_id' => (int) $manager->id];
            $this->journal->handle($state['seat'], 'merged', [
                'action' => 'legacy_bill_combined', 'client_request_id' => $input['client_request_id'],
                'request_hash' => $requestHash, 'source_order_uuid' => $source->uuid,
                'approved_by_staff_id' => (int) $manager->id, 'reason' => $input['reason'],
                'item_id_map' => $copy['item_id_map'], 'result' => $result,
            ], (int) $device->id);
            return $result;
        });
    }

    /** Caller owns a read-only snapshot OR the resolver's entire branch graph. */
    private function state(Device $device, int $tableId, string $sourceUuid): array
    {
        $table = Table::query()->where('company_id', $device->company_id)->whereKey($tableId)
            ->where('status', 'active')->whereIn('floor_id', DB::table('pos_floors')->select('id')
            ->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
            ->whereNull('deleted_at'))->first();
        if ($table === null) {
            throw new QrDineInException('table_not_found', 404, 'The table was not found in this branch.');
        }
        $seats = TableSession::query()->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
            ->where('table_id', $tableId)->whereIn('status', TableSession::LIVE_STATUSES)->get();
        $seat = $seats->first();
        if ($seats->count() !== 1 || $seat->status !== TableSession::STATUS_OPEN || $seat->merged_into_id !== null
            || $seat->billing_at !== null) {
            throw $this->refusal('combine_table_not_open', 'Review from the open primary table before starting payment.');
        }
        $orders = Order::query()->where('company_id', $device->company_id)->where('branch_id', $device->branch_id);
        $source = (clone $orders)->where('uuid', $sourceUuid)->first();
        $target = (clone $orders)->find($seat->order_id);
        if ($source === null || $target === null) {
            throw new QrDineInException('order_not_found', 404, 'Both bills must exist in this branch.');
        }
        if ((int) $source->device_id !== (int) $device->id) {
            throw $this->refusal('combine_source_device_required', 'Use the device holding the original local bill.');
        }
        if ($source->id === $target->id || $source->table_id !== $table->id || $target->table_id !== $table->id
            || $target->table_session_id !== $seat->id || $source->order_type !== 'dine_in' || $target->order_type !== 'dine_in'
            || ! in_array($source->source, ['main_pos', 'handheld'], true) || $target->source !== Order::SOURCE_QR_WEB
            || ! in_array($source->status, [Order::STATUS_OPEN, Order::STATUS_HELD], true) || $target->status !== Order::STATUS_OPEN
            || $source->closed_at !== null || $target->closed_at !== null || $source->qr_session_id !== null
            || $source->table_session_id !== null || $target->qr_session_id === null) {
            throw $this->refusal('combine_bill_ineligible', 'Only a separate unpaid staff bill and the open QR bill can be combined.');
        }
        $credential = QrSession::query()->whereKey($target->qr_session_id)->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->where('table_id', $tableId)->where('table_session_id', $seat->id)->first();
        if ($credential === null || $credential->released_at !== null) {
            throw $this->refusal('combine_bill_ineligible', 'The QR bill credential does not match this seating.');
        }
        foreach ([$source, $target] as $order) {
            foreach (['charge_device_id', 'charge_amount_baisas', 'charge_roundup_amount_baisas', 'charge_claimed_at',
                'charge_deadline_at', 'charge_outcome', 'transferred_to_device_id', 'transferred_from_device_id',
                'transferred_at', 'delivery_provider_id'] as $field) {
                if ($order->$field !== null) {
                    throw $this->refusal('combine_payment_or_transfer', 'Resolve payment or transfer evidence before combining.');
                }
            }
            if (DB::table('pos_payments')->where('order_id', $order->id)->exists()) {
                throw $this->refusal('combine_payment_or_transfer', 'A bill with payment records cannot be combined.');
            }
            foreach (['pos_loyalty_transactions', 'pos_sale_commissions', 'pos_roundup_donations'] as $ledger) {
                if (DB::table($ledger)->where('order_id', $order->id)->exists()) {
                    throw $this->refusal('combine_accounting_evidence', 'This bill has accounting evidence requiring separate review.');
                }
            }
            foreach (['pos_stock_movements', 'pos_product_stock_movements'] as $ledger) {
                if (DB::table($ledger)->where('reference_type', 'pos_orders')->where('reference_id', $order->id)->exists()) {
                    throw $this->refusal('combine_accounting_evidence', 'This bill has stock evidence requiring separate review.');
                }
            }
        }
        if (TableSession::query()->where('order_id', $source->id)->exists()
            || QrOrderRound::query()->where('order_id', $source->id)->exists()) {
            throw $this->refusal('combine_bill_ineligible', 'The source already belongs to a shared bill history.');
        }
        // Do not silently join tables or absorb a third independently payable bill.
        if (DB::table('pos_order_tables')->whereIn('order_id', [$source->id, $target->id])->exists()
            || TableSession::query()->where('merged_into_id', $seat->id)->whereIn('status', TableSession::LIVE_STATUSES)->exists()
            || (clone $orders)->whereIn('status', ListTableBoardAction::UNPAID_STATUSES)->where(function ($query) use ($tableId): void {
                $query->where('table_id', $tableId)->orWhereIn('id', DB::table('pos_order_tables')->select('order_id')->where('table_id', $tableId));
            })->whereNotIn('id', [$source->id, $target->id])->exists()) {
            throw $this->refusal('combine_coverage_conflict', 'Joined tables or additional bills need separate manager review.');
        }
        TableBillAdjustmentView::refuseUnsupported($source);
        TableBillAdjustmentView::refuseUnsupported($target);
        $sourceSnapshot = $this->frozen->snapshot($source);
        $targetSnapshot = $this->frozen->snapshot($target);
        $this->frozen->assertImportable($sourceSnapshot);
        $money = $this->frozen->totals($targetSnapshot);
        $accepted = array_filter($targetSnapshot['rounds'], fn ($round): bool => $round['status'] === QrOrderRound::STATUS_ACCEPTED);
        $subtotal = (int) array_sum(array_column($accepted, 'subtotal_baisas'));
        $tax = (int) array_sum(array_column($accepted, 'tax_baisas'));
        $total = (int) array_sum(array_column($accepted, 'total_baisas'));
        // LAUNCH-P4 — both bills must price tax the same way (inclusive or on top).
        if ((bool) $source->prices_include_tax !== (bool) $target->prices_include_tax
            || $targetSnapshot['comps'] !== [] || $money['comp_total_baisas'] !== 0
            || $money['subtotal_baisas'] !== $subtotal || $money['tax_total_baisas'] !== $tax
            || $money['grand_total_baisas'] !== $total
            || $money['discount_total_baisas'] !== BillMoney::discount($subtotal, $tax, $total, (bool) $target->prices_include_tax)) {
            throw $this->refusal('combine_accounting_unsupported', 'The QR bill does not match its frozen rounds. Keep both bills unchanged.');
        }

        return ['table' => $table, 'seat' => $seat, 'source' => $source, 'target' => $target, 'credential' => $credential,
            'source_snapshot' => $sourceSnapshot, 'target_snapshot' => $targetSnapshot];
    }

    private function signature(Device $device, array $state, int $expires): string
    {
        if (strlen((string) config('app.key')) < 32) {
            throw new QrDineInException('combine_unavailable', 503, 'Secure preview signing is unavailable.');
        }

        return hash_hmac('sha256', json_encode([
            'v1', (int) $device->id, (int) $device->company_id, (int) $device->branch_id, $expires,
            $state['table']->getRawOriginal(), $state['seat']->getRawOriginal(), $state['credential']->getRawOriginal(),
            $state['source_snapshot'], $state['target_snapshot'],
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function attended(Device $device): void
    {
        if ($device->trashed() || $device->status !== 'active' || ! $device->isAssigned()
            || ! in_array($device->device_type, ['fixed_pos', 'handheld'], true)) {
            throw $this->refusal('device_not_attended', 'An active assigned till or handheld is required.');
        }
    }

    /** LAUNCH-P5 — the approver positions (approvals.give) of the resolved tick list. */
    private function policy(Device $device): mixed
    {
        return $this->managers->approvalPositions((int) $device->company_id);
    }

    private function refusal(string $code, string $message): QrDineInException
    {
        return new QrDineInException($code, 409, $message);
    }
}
