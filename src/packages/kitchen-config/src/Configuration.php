<?php

declare(strict_types=1);

namespace App\Kitchen;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Internal configuration command boundary. K2 must supply scope from the merchant session, never request JSON. */
final class Configuration
{
    public function __construct(private readonly Routing $routing) {}

    public function branch(int $company, int $branch): object
    {
        KitchenFault::require(DB::table('pos_branches')->where('id', $branch)->where('company_id', $company)->whereNull('deleted_at')->exists(), 'branch_not_found', 404);
        DB::table('pos_kv2_branches')->insertOrIgnore(['company_id' => $company, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('pos_kv2_branches')->where('company_id', $company)->where('branch_id', $branch)->lockForUpdate()->first();
    }

    public function policyPreview(int $company, array $authorizedBranches, ?int $branch, string $source, string $mode): array
    {
        KitchenFault::require(in_array($source, ['staff', 'qr_web', 'customer_tablet'], true), 'invalid_source', 422);
        KitchenFault::require(in_array($mode, ['manual', 'immediate'], true), 'release_mode_unsupported', 422);
        $all = DB::table('pos_branches')->where('company_id', $company)->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $targets = $branch === null ? $all : [$branch];
        KitchenFault::require(array_diff($targets, $all) === [] && array_diff($targets, $authorizedBranches) === [], 'branch_forbidden', 403);

        return ['branches' => $targets, 'source' => $source, 'mode' => $mode, 'all_branches' => $branch === null, 'future_branches' => $branch === null];
    }

    public function setPolicy(int $company, array $authorizedBranches, ?int $branch, string $source, string $mode, string $actor): array
    {
        return DB::transaction(function () use ($company, $authorizedBranches, $branch, $source, $mode, $actor) {
            // Company row serializes all-branch updates with other company configuration writes.
            DB::table('pos_companies')->where('id', $company)->lockForUpdate()->first();
            $preview = $this->policyPreview($company, $authorizedBranches, $branch, $source, $mode);
            if ($branch === null) {
                DB::table('pos_kv2_policies')->where('company_id', $company)->where('source', $source)->where('branch_scope', '>', 0)->delete();
            }
            DB::table('pos_kv2_policies')->updateOrInsert(['company_id' => $company, 'branch_scope' => $branch ?? 0, 'source' => $source], ['release_mode' => $mode, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($preview['branches'] as $id) {
                $state = $this->branch($company, $id);
                $this->save($state, $this->latest($state), $actor);
            }
            $this->audit($company, $branch, $actor, 'policy', $preview);

            return $preview;
        }, 5);
    }

    public function setRouting(int $company, array $authorizedBranches, int $branch, array $bundle, string $actor): array
    {
        KitchenFault::require(in_array($branch, $authorizedBranches, true), 'branch_forbidden', 403);
        $bundle = Routing::validate($bundle);
        // References must belong to this merchant; identifiers from another tenant never become route selectors.
        foreach ($bundle['rules'] as $rule) {
            $table = $rule['kind'] === 'item' ? 'pos_products' : 'pos_product_categories';
            KitchenFault::require(DB::table($table)->where('id', $rule['reference_id'])->where('company_id', $company)->whereNull('deleted_at')->exists(), 'route_reference_not_found', 422);
        }

        return DB::transaction(function () use ($company, $branch, $bundle, $actor) {
            DB::table('pos_companies')->where('id', $company)->lockForUpdate()->first();
            $state = $this->branch($company, $branch);

            return $this->save($state, $bundle, $actor);
        }, 5);
    }

    private function latest(object $state): array
    {
        $row = DB::table('pos_kv2_configurations')->where('company_id', $state->company_id)->where('branch_id', $state->branch_id)->where('version', $state->desired_version)->first();

        return $row ? Wire::read($row->bundle) : ['areas' => [], 'destinations' => [], 'rules' => [], 'all_items' => [], 'fallback' => ['areas' => [], 'destinations' => []]];
    }

    private function save(object $state, array $bundle, string $actor): array
    {
        $policies = [];
        foreach (['staff', 'qr_web', 'customer_tablet'] as $source) {
            $policies[$source] = DB::table('pos_kv2_policies')->where('company_id', $state->company_id)->where('source', $source)->whereIn('branch_scope', [0, $state->branch_id])->orderByDesc('branch_scope')->value('release_mode') ?? 'manual';
        }
        $bundle['policies'] = $policies;
        $version = $state->desired_version + 1;
        $hash = Wire::hash($bundle);
        DB::table('pos_kv2_configurations')->insert(['company_id' => $state->company_id, 'branch_id' => $state->branch_id, 'version' => $version, 'bundle' => Wire::json($bundle), 'bundle_hash' => $hash, 'actor' => $actor, 'created_at' => now()]);
        DB::table('pos_kv2_branches')->where('id', $state->id)->update(['desired_version' => $version, 'updated_at' => now()]);
        $this->audit($state->company_id, $state->branch_id, $actor, 'configuration', ['version' => $version, 'hash' => $hash]);

        return ['version' => $version, 'hash' => $hash, 'bundle' => $bundle];
    }

    public function enroll(int $company, array $authorizedBranches, int $deviceId, string $thumbprint, array $areas, string $actor): void
    {
        $device = $this->device($company, $deviceId);
        KitchenFault::require((int) $device->company_id === $company && in_array((int) $device->branch_id, $authorizedBranches, true) && (in_array($device->device_type, ['fixed_pos', 'handheld'], true) || $device->device_type === 'kitchen_display'), 'device_forbidden', 403);
        KitchenFault::require(preg_match('/^[a-f0-9]{64}$/D', $thumbprint) === 1 && $device->assignment_activated_at !== null, 'invalid_device_identity', 422);
        DB::transaction(function () use ($company, $device, $thumbprint, $areas, $actor) {
            $state = $this->branch($company, (int) $device->branch_id);
            $bundle = $this->latest($state);
            KitchenFault::require(array_diff($areas, array_column($bundle['areas'], 'id')) === [], 'area_not_found', 422);
            DB::table('pos_kv2_devices')->updateOrInsert(['device_id' => $device->id], ['company_id' => $company, 'branch_id' => $device->branch_id, 'assignment' => Carbon::parse($device->assignment_activated_at)->toIso8601String(), 'certificate_thumbprint' => $thumbprint, 'areas' => Wire::json(array_values(array_unique($areas))), 'protocol_version' => 1, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($company, (int) $device->branch_id, $actor, 'enroll', ['device_id' => $device->id, 'areas' => $areas]);
        }, 5);
    }

    public function assign(int $company, array $authorizedBranches, int $deviceId, string $actor, string $isolationEvidence): array
    {
        $device = $this->device($company, $deviceId);
        KitchenFault::require(in_array($device->device_type, ['fixed_pos', 'handheld'], true) && (int) (int) $device->company_id === $company && in_array((int) $device->branch_id, $authorizedBranches, true), 'coordinator_forbidden', 403);
        KitchenFault::require(strlen(trim($isolationEvidence)) >= 12, 'controlled_cutover_evidence_required', 422);

        return DB::transaction(function () use ($company, $device, $actor, $isolationEvidence) {
            $s = $this->branch($company, (int) $device->branch_id);
            $enrollment = DB::table('pos_kv2_devices')->where('device_id', $device->id)->where('company_id', $company)->where('branch_id', $device->branch_id)->where('enabled', true)->first();
            KitchenFault::require($enrollment && $enrollment->assignment === $this->assignment($device->assignment_activated_at), 'device_not_enrolled', 403);
            KitchenFault::require(in_array($s->mode, ['legacy', 'suspended'], true), 'suspend_before_handover');
            KitchenFault::require(! DB::table('pos_kv2_deliveries')->whereIn('submission_id', DB::table('pos_kv2_submissions')->select('id')->where('company_id', $company)->where('branch_id', $device->branch_id))->whereNotIn('state', ['confirmed', 'cancelled'])->exists(), 'delivery_reconciliation_required');
            KitchenFault::require(! DB::table('pos_kitchen_tickets')->where('company_id', $company)->where('branch_id', $device->branch_id)->whereNull('print_result')->exists(), 'legacy_claim_reconciliation_required');
            foreach (DB::table('pos_devices')->where('company_id', $company)->where('branch_id', $device->branch_id)->whereNull('deleted_at')->whereIn('device_type', ['fixed_pos', 'handheld'])->where('status', 'active')->get() as $peer) {
                KitchenFault::require(DB::table('pos_kv2_devices')->where('device_id', $peer->id)->where('enabled', true)->where('assignment', $this->assignment($peer->assignment_activated_at))->exists(), 'legacy_device_cutover_required');
            }
            $epoch = $s->epoch + 1;
            DB::table('pos_kv2_branches')->where('id', $s->id)->update(['coordinator_id' => $device->id, 'coordinator_assignment' => $enrollment->assignment, 'epoch' => $epoch, 'mode' => 'pending', 'activation_state' => 'idle', 'activation_id' => null, 'staged_version' => null, 'updated_at' => now()]);
            $this->audit($company, (int) $device->branch_id, $actor, 'assign', ['device_id' => $device->id, 'epoch' => $epoch, 'isolation_evidence' => $isolationEvidence]);

            return ['epoch' => $epoch];
        }, 5);
    }

    public function suspend(int $company, array $authorizedBranches, int $branch, string $actor): void
    {
        KitchenFault::require(in_array($branch, $authorizedBranches, true), 'branch_forbidden', 403);
        DB::transaction(function () use ($company, $branch, $actor) {
            $s = $this->branch($company, $branch);
            DB::table('pos_kv2_branches')->where('id', $s->id)->update(['mode' => 'suspended', 'epoch' => $s->epoch + 1, 'updated_at' => now()]);
            $this->audit($company, $branch, $actor, 'suspend', ['epoch' => $s->epoch + 1]);
        }, 5);
    }

    private function device(int $company, int $id): object
    {
        $d = DB::table('pos_devices')->where('id', $id)->where('company_id', $company)->where('status', 'active')->whereNull('deleted_at')->first();
        KitchenFault::require($d !== null && $d->branch_id !== null, 'device_forbidden', 403);

        return $d;
    }

    private function assignment(?string $date): ?string
    {
        return $date === null ? null : Carbon::parse($date)->toIso8601String();
    }

    public function audit(int $company, ?int $branch, string $actor, string $action, array $detail): void
    {
        DB::table('pos_kv2_audit')->insert(['company_id' => $company, 'branch_id' => $branch, 'actor' => $actor, 'action' => $action, 'detail' => Wire::json($detail), 'created_at' => now()]);
    }
}
