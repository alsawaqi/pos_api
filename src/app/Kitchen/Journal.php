<?php

declare(strict_types=1);

namespace App\Kitchen;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Journal
{
    public function apply(Access $access, array $input, callable $operation): array
    {
        Validator::make($input, ['protocol_version' => ['required', 'integer', Rule::in([1])], 'event_id' => 'required|uuid', 'epoch' => 'required|integer|min:1', 'occurred_at' => 'required|date'])->validate();
        $identity = $access->identity();
        $hash = Wire::hash(['input' => $input, 'device_id' => $identity['device_id'], 'staff_id' => $access->staffId, 'assignment' => $identity['assignment']]);

        return DB::transaction(function () use ($access, $input, $operation, $identity, $hash) {
            $state = DB::table('pos_kv2_branches')->where('company_id', $identity['company_id'])->where('branch_id', $identity['branch_id'])->lockForUpdate()->first();
            KitchenFault::require($state && in_array($state->mode, ['active', 'pending'], true), 'kitchen_not_active');
            KitchenFault::require((int) $state->epoch === $input['epoch'] && $input['epoch'] === $identity['epoch'], 'stale_coordinator_epoch');
            $query = DB::table('pos_kv2_events')->where('company_id', $identity['company_id'])->where('branch_id', $identity['branch_id'])->where('uuid', $input['event_id']);
            if ($old = $query->first()) {
                KitchenFault::require(hash_equals($old->payload_hash, $hash), 'event_payload_conflict');

                return [...Wire::read($old->result), 'replayed' => true];
            }
            $result = $operation($state);
            $sequence = $state->sequence + 1;
            $receipt = [...$result, 'sequence' => $sequence, 'event_id' => $input['event_id'], 'durable' => true, 'replayed' => false, 'accepted_at' => $access->eventTime()->toIso8601String(), 'actor' => ['device_id' => $identity['device_id'], 'staff_id' => $access->staffId, 'assignment' => $identity['assignment']]];
            DB::table('pos_kv2_events')->insert(['company_id' => $identity['company_id'], 'branch_id' => $identity['branch_id'], 'uuid' => $input['event_id'], 'sequence' => $sequence, 'device_id' => $identity['device_id'], 'staff_id' => $access->staffId, 'epoch' => $state->epoch, 'action' => $input['action'], 'payload_hash' => $hash, 'payload' => Wire::json($input), 'result' => Wire::json($receipt), 'occurred_at' => $input['occurred_at'], 'recorded_at' => now()]);
            DB::table('pos_kv2_branches')->where('id', $state->id)->update(['sequence' => $sequence, 'updated_at' => now()]);

            return $receipt;
        }, 5);
    }
}
