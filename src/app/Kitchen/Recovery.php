<?php

declare(strict_types=1);

namespace App\Kitchen;

use App\Models\PosStaff;
use App\Support\Staff\PositionPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Operator-attested forensic archive. Never invokes Journal or dispatch. */
final class Recovery
{
    private const TABLES = [
        'receipts' => ['event_id', 'payload_hash', 'origin', 'result', 'sequence'],
        'orders' => ['uuid', 'source_key', 'round_key', 'document'],
        'jobs' => ['uuid', 'submission_uuid', 'document'],
        'outbox' => ['sequence', 'event_id', 'event', 'origin', 'state', 'attempts', 'next_ms', 'error', 'cloud_receipt'],
        'intents' => ['id', 'event', 'origin', 'domain_id', 'domain_hash', 'state', 'receipt', 'error'],
        'reports' => ['attempt_id', 'job_id', 'result', 'acknowledged'],
        'cloud_inbox' => ['event_id', 'document'],
    ];

    private function credentialFree(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }
        $forbidden = ['grant', 'host_grant', 'device_token', 'staff_token', 'access_token', 'refresh_token', 'bearer', 'private_key', 'private_keys', 'credential_id', 'host_credential_id', 'public_keys', 'clock', 'root_certificate'];
        foreach ($value as $key => $v) {
            KitchenFault::require(! in_array((string) $key, $forbidden, true), 'checkpoint_authority_forbidden', 422);
            $this->credentialFree($v);
        }
    }

    private function authorize(Access $a): void
    {
        $staff = PosStaff::query()->find($a->staffId);
        KitchenFault::require($staff && app(PositionPermissions::class)->allows((int) $a->device->company_id, $staff->position, 'approvals.give'), 'recovery_supervisor_required', 403);
    }

    public function archive(Access $a, array $input): array
    {
        $this->authorize($a);
        Validator::make($input, [
            'request_id' => 'required|uuid', 'checkpoint_json' => 'required|string|max:8388608',
            'checkpoint_hash' => 'required|regex:/^[a-f0-9]{64}$/',
            'reason' => 'required|string|min:10|max:1000',
            'range_reconciliation' => 'required|string|min:10|max:2000',
            'old_host_isolation' => 'nullable|string|min:10|max:2000',
        ])->validate();
        $raw = $input['checkpoint_json'];
        KitchenFault::require(hash_equals(hash('sha256', $raw), $input['checkpoint_hash']), 'checkpoint_hash_mismatch', 422);
        try {
            $d = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new KitchenFault('checkpoint_invalid', 422);
        }
        KitchenFault::require(is_array($d) && ($d['format'] ?? null) === 'mithqal-kitchen-held-v1' && array_diff(array_keys($d), ['format', 'identity', 'configuration', 'sequence', 'activation', 'tables']) === [], 'checkpoint_format_invalid', 422);
        $this->credentialFree($d);
        KitchenFault::require(is_array($d['configuration'] ?? null) && array_diff(array_keys($d['configuration']), ['applied_version', 'bundle_hash', 'bundle']) === [] && count($d['configuration']) === 3, 'checkpoint_configuration_invalid', 422);
        KitchenFault::require(is_int($d['configuration']['applied_version']) && is_string($d['configuration']['bundle_hash']) && is_array($d['configuration']['bundle']) && hash_equals(Wire::hash($d['configuration']['bundle']), $d['configuration']['bundle_hash']), 'checkpoint_configuration_invalid', 422);
        if (($d['activation'] ?? null) !== null) {
            KitchenFault::require(is_array($d['activation']) && array_diff(array_keys($d['activation']), ['id', 'phase', 'events', 'next_configuration']) === [], 'checkpoint_activation_invalid', 422);
        }
        $id = $d['identity'] ?? [];
        foreach (['company_id', 'branch_id', 'device_id', 'epoch'] as $k) {
            KitchenFault::require(is_int($id[$k] ?? null) && $id[$k] > 0, 'checkpoint_identity_invalid', 422);
        }
        KitchenFault::require(is_string($id['assignment'] ?? null) && strlen($id['assignment']) > 0 && array_diff(array_keys($id), ['company_id', 'branch_id', 'device_id', 'epoch', 'assignment']) === [], 'checkpoint_identity_invalid', 422);
        KitchenFault::require($id['company_id'] === (int) $a->device->company_id && $id['branch_id'] === (int) $a->device->branch_id, 'recovery_branch_mismatch', 403);
        KitchenFault::require(is_int($d['sequence'] ?? null) && $d['sequence'] >= 0 && is_array($d['configuration'] ?? null) && is_array($d['tables'] ?? null), 'checkpoint_invalid', 422);
        KitchenFault::require(array_diff(array_keys($d['tables']), array_keys(self::TABLES)) === [] && count($d['tables']) === count(self::TABLES), 'checkpoint_tables_invalid', 422);
        foreach (self::TABLES as $table => $columns) {
            $rows = $d['tables'][$table];
            KitchenFault::require(is_array($rows) && array_is_list($rows), 'checkpoint_rows_invalid', 422);
            foreach ($rows as $row) {
                KitchenFault::require(is_array($row) && array_diff(array_keys($row), $columns) === [] && count($row) === count($columns), 'checkpoint_columns_invalid', 422);
                foreach ($row as $v) {
                    KitchenFault::require(is_null($v) || is_int($v) || is_string($v), 'checkpoint_value_invalid', 422);
                }
                if (isset($row['origin']) && $table !== 'receipts') {
                    $origin = json_decode($row['origin'], true);
                    KitchenFault::require(is_array($origin) && ($origin['company_id'] ?? null) === $id['company_id'] && ($origin['branch_id'] ?? null) === $id['branch_id'] && ($origin['epoch'] ?? null) === $id['epoch'], 'checkpoint_origin_mismatch', 422);
                }
                foreach (['origin', 'event', 'document', 'result', 'receipt', 'cloud_receipt'] as $k) {
                    if (isset($row[$k])) {
                        try {
                            $this->credentialFree(json_decode($row[$k], true, 128, JSON_THROW_ON_ERROR));
                        } catch (\JsonException) {
                            throw new KitchenFault('checkpoint_document_invalid', 422);
                        }
                    }
                }
            }
        }
        $receipts = [];
        foreach ($d['tables']['receipts'] as $row) {
            KitchenFault::require(! isset($receipts[$row['event_id']]), 'checkpoint_event_invalid', 422);
            $receipts[$row['event_id']] = $row;
        }
        KitchenFault::require(count($receipts) === count($d['tables']['outbox']) && count($receipts) === $d['sequence'], 'checkpoint_sequence_invalid', 422);
        $seen = [];
        $sequences = [];
        foreach ($d['tables']['outbox'] as $row) {
            $event = Wire::read($row['event']);
            $origin = Wire::read($row['origin']);
            $receipt = $receipts[$row['event_id']] ?? null;
            KitchenFault::require($receipt && ($event['event_id'] ?? null) === $row['event_id'] && ! isset($seen[$row['event_id']]), 'checkpoint_event_invalid', 422);
            $seen[$row['event_id']] = true;
            KitchenFault::require(is_int($row['sequence']) && $row['sequence'] > 0 && $row['sequence'] <= $d['sequence'] && ! isset($sequences[$row['sequence']]) && $receipt['sequence'] === $row['sequence'], 'checkpoint_sequence_invalid', 422);
            $sequences[$row['sequence']] = true;
            $actor = Wire::read($receipt['origin']);
            $result = Wire::read($receipt['result']);
            foreach (['device_id', 'staff_id', 'assignment'] as $k) {
                KitchenFault::require(($actor[$k] ?? null) === ($origin[$k] ?? null), 'checkpoint_receipt_invalid', 422);
            }
            foreach (['company_id', 'branch_id', 'epoch'] as $k) {
                if (isset($actor[$k])) {
                    KitchenFault::require($actor[$k] === $id[$k], 'checkpoint_origin_mismatch', 422);
                }
            }
            KitchenFault::require(($result['event_id'] ?? null) === $row['event_id'] && ($result['sequence'] ?? null) === $row['sequence'], 'checkpoint_receipt_invalid', 422);
            KitchenFault::require(hash_equals($receipt['payload_hash'], Wire::hash(['input' => $event, 'device_id' => $origin['device_id'], 'staff_id' => $origin['staff_id'], 'assignment' => $origin['assignment']])), 'checkpoint_payload_mismatch', 422);
        }

        return DB::transaction(function () use ($a, $input, $d, $id): array {
            $branch = DB::table('pos_kv2_branches')->where('id', $a->branch->id)->lockForUpdate()->first();
            KitchenFault::require($branch && (int) $branch->epoch === (int) $a->branch->epoch && (int) $branch->coordinator_id === (int) $a->device->id && $branch->coordinator_assignment === $a->binding->assignment, 'recovery_target_changed', 409);
            $target = array_intersect_key($a->identity(), array_flip(['company_id', 'branch_id', 'device_id', 'assignment', 'epoch']));
            $replacement = $id['device_id'] !== $target['device_id'] || $id['assignment'] !== $target['assignment'] || $id['epoch'] !== $target['epoch'];
            KitchenFault::require(! $replacement || (strlen($input['old_host_isolation'] ?? '') >= 10 && $target['epoch'] > $id['epoch']), 'recovery_isolation_required', 409);
            $requestHash = hash('sha256', Wire::json($input));
            $query = DB::table('pos_kv2_audit')->where('company_id', $id['company_id'])->where('branch_id', $id['branch_id'])->where('action', 'recovery_archive');
            foreach ($query->get() as $old) {
                $detail = Wire::read($old->detail);
                if ($detail['request_id'] === $input['request_id']) {
                    KitchenFault::require(hash_equals($detail['request_hash'], $requestHash) && $detail['receipt']['target_identity'] === $target, 'recovery_request_conflict', 409);

                    return $detail['receipt'];
                }
            }
            $receipt = ['request_id' => $input['request_id'], 'checkpoint_hash' => $input['checkpoint_hash'], 'durable' => true, 'disposition' => 'operator_attested_history_held', 'original_authority' => 'unverified', 'original_identity' => $id, 'target_identity' => $target, 'sequence' => $d['sequence'], 'archived_at' => now()->toIso8601String()];
            DB::table('pos_kv2_audit')->insert(['company_id' => $id['company_id'], 'branch_id' => $id['branch_id'], 'actor' => 'staff:'.$a->staffId.':device:'.$a->device->id, 'action' => 'recovery_archive', 'detail' => Wire::json(['request_id' => $input['request_id'], 'request_hash' => $requestHash, 'receipt' => $receipt, 'checkpoint_json' => $input['checkpoint_json'], 'reason' => $input['reason'], 'range_reconciliation' => $input['range_reconciliation'], 'old_host_isolation' => $input['old_host_isolation'] ?? null]), 'created_at' => now()]);

            return $receipt;
        }, 5);
    }

    public function read(Access $a, string $requestId): array
    {
        $this->authorize($a);
        foreach (DB::table('pos_kv2_audit')->where('company_id',$a->device->company_id)->where('branch_id',$a->device->branch_id)->where('action','recovery_archive')->get() as $row) {
            $d = Wire::read($row->detail);
            if ($d['request_id'] === $requestId) {
                return $d;
            }
        }
        throw new KitchenFault('recovery_archive_not_found',404);
    }
}
