<?php

declare(strict_types=1);

namespace App\Kitchen;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class Deliveries
{
    public function query(Access $a)
    {
        return DB::table('pos_kv2_deliveries')->whereIn('submission_id', DB::table('pos_kv2_submissions')->select('id')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id));
    }

    public function apply(Access $a, array $i): array
    {
        Validator::make($i, ['delivery_uuid' => 'required|uuid'])->validate();
        $d = $this->query($a)->where('uuid', $i['delivery_uuid'])->first();
        KitchenFault::require($d !== null, 'delivery_not_found', 404);
        $linkState = DB::table('pos_kv2_submissions')->where('id', $d->submission_id)->value('link_state');
        KitchenFault::require($linkState !== 'review_required' || $i['action'] === 'delivery_result', 'domain_review_required');
        switch ($i['action']) {
            case 'resume_delivery':
                KitchenFault::require(in_array($d->state, ['paused', 'failed_before_send'], true), 'delivery_not_paused');
                DB::table('pos_kv2_deliveries')->where('id', $d->id)->update(['state' => 'queued', 'updated_at' => now()]);
                break;
            case 'claim_delivery':
                Validator::make($i, ['attempt_uuid' => 'required|uuid'])->validate();
                KitchenFault::require(in_array($d->state, ['queued', 'failed_before_send'], true), 'delivery_not_retryable');
                KitchenFault::require(! DB::table('pos_kv2_attempts')->where('uuid', $i['attempt_uuid'])->exists(), 'attempt_exists');
                DB::table('pos_kv2_attempts')->insert(['uuid' => $i['attempt_uuid'], 'delivery_id' => $d->id, 'device_id' => $a->device->id, 'epoch' => $a->branch->epoch, 'state' => 'claimed', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('pos_kv2_deliveries')->where('id', $d->id)->update(['state' => 'claimed', 'attempt_uuid' => $i['attempt_uuid'], 'updated_at' => now()]);
                break;
            case 'delivery_result':
                Validator::make($i, ['attempt_uuid' => 'required|uuid', 'result' => 'required|in:sending,failed_before_send,sent_unconfirmed,uncertain', 'evidence' => 'nullable|string|max:500'])->validate();
                KitchenFault::require($d->attempt_uuid === $i['attempt_uuid'], 'attempt_mismatch');
                $attempt = DB::table('pos_kv2_attempts')->where('uuid', $i['attempt_uuid'])->first();
                KitchenFault::require($attempt && (int) $attempt->device_id === (int) $a->device->id && (int) $attempt->epoch === (int) $a->branch->epoch, 'attempt_mismatch');
                $allowed = ['claimed' => ['sending', 'failed_before_send', 'uncertain'], 'sending' => ['sent_unconfirmed', 'uncertain']];
                KitchenFault::require(in_array($i['result'], $allowed[$d->state] ?? [], true), 'delivery_result_conflict');
                DB::table('pos_kv2_attempts')->where('id', $attempt->id)->update(['state' => $i['result'], 'result' => Wire::json(['evidence' => $i['evidence'] ?? null, 'meaning' => 'Transport state only; no proof of physical paper.']), 'updated_at' => now()]);
                DB::table('pos_kv2_deliveries')->where('id', $d->id)->update(['state' => $i['result'], 'updated_at' => now()]);
                break;
            case 'reassign_delivery':
                Validator::make($i, ['destination_uuid' => 'required|uuid', 'reason' => 'required|string|min:5|max:500'])->validate();
                KitchenFault::require(in_array($d->state, ['queued', 'paused', 'claimed', 'failed_before_send'], true), 'uncertain_delivery_requires_review');
                $configuration = DB::table('pos_kv2_configurations')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('version', $a->branch->applied_version)->first();
                $destination = collect(Wire::read($configuration?->bundle)['destinations'] ?? [])->firstWhere('id', $i['destination_uuid']);
                KitchenFault::require($destination && $destination['id'] !== $d->destination_uuid, 'destination_not_found', 404);
                DB::table('pos_kv2_deliveries')->where('id', $d->id)->update(['state' => 'cancelled', 'updated_at' => now()]);
                DB::table('pos_kv2_attempts')->where('delivery_id', $d->id)->where('state', 'claimed')->update(['state' => 'cancelled', 'updated_at' => now()]);
                $new = Ids::stable('reassign:'.$i['event_id']);
                DB::table('pos_kv2_deliveries')->insert(['uuid' => $new, 'submission_id' => $d->submission_id, 'revision' => $d->revision, 'destination_uuid' => $destination['id'], 'purpose' => 'reassign', 'copy_key' => $i['event_id'], 'snapshot' => Wire::json([...Wire::read($d->snapshot), 'destination' => $destination, 'reassigned_from' => $d->uuid, 'reason' => $i['reason']]), 'state' => $destination['paused'] ? 'paused' : 'queued', 'created_at' => now(), 'updated_at' => now()]);

                return ['delivery_uuid' => $new, 'state' => $destination['paused'] ? 'paused' : 'queued', 'purpose' => 'reassign'];
            case 'reprint':
                Validator::make($i, ['reason' => 'required|string|min:5|max:500'])->validate();
                KitchenFault::require(in_array($d->state, ['sent_unconfirmed', 'uncertain', 'confirmed'], true), 'reprint_not_available');
                $new = Ids::stable('reprint:'.$i['event_id']);
                DB::table('pos_kv2_deliveries')->insert(['uuid' => $new, 'submission_id' => $d->submission_id, 'revision' => $d->revision, 'destination_uuid' => $d->destination_uuid, 'purpose' => 'reprint', 'copy_key' => $i['event_id'], 'snapshot' => Wire::json([...Wire::read($d->snapshot), 'original_purpose' => Wire::read($d->snapshot)['original_purpose'] ?? $d->purpose, 'reprint_of' => $d->uuid, 'reason' => $i['reason']]), 'state' => 'queued', 'created_at' => now(), 'updated_at' => now()]);

                return ['delivery_uuid' => $new, 'state' => 'queued', 'purpose' => 'reprint'];
            default:throw new KitchenFault('action_unsupported', 422);
        }
        $updated = $this->query($a)->where('id', $d->id)->first();

        return ['delivery_uuid' => $d->uuid, 'state' => $updated->state, 'attempt_uuid' => $updated->attempt_uuid];
    }
}
