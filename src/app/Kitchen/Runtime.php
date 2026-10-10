<?php

declare(strict_types=1);

namespace App\Kitchen;

use App\Models\Device;
use App\Models\PosStaff;
use App\Support\Staff\StaffBranches;
use Illuminate\Support\Facades\DB;

/** Authenticated bootstrap only; never published through discovery or LAN. */
final class Runtime
{
    public function forHost(Access $a): array
    {
        ['root_certificate' => $root, 'public_keys' => $keys] = $this->trust();
        $grant = app(Grants::class)->issue($a->identity(), ['kitchen.host'], null);
        $claims = app(Grants::class)->verify($grant['grant'], $a->identity(), 'kitchen.host');
        $identity = $a->identity();
        $thumbprint = $identity['cnf']['x5t#S256'];
        unset($identity['cnf']);
        $peers = [];
        foreach (DB::table('pos_kv2_devices')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('enabled', true)->get() as $binding) {
            $device = Device::query()->whereKey($binding->device_id)->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('status', 'active')->first();
            if (! $device || $binding->assignment !== $device->assignment_activated_at?->toIso8601String()) {
                continue;
            }
            $peers[(string) $device->id] = ['enabled' => true, 'thumbprint' => $this->base64(hex2bin($binding->certificate_thumbprint)), 'assignment' => $binding->assignment, 'area_ids' => Wire::read($binding->areas), 'device_type' => $device->device_type];
        }
        $products = Catalogue::query((int) $a->device->company_id, (int) $a->device->branch_id)->limit(10001)->get(['id', 'category_id']);
        KitchenFault::require($products->count() <= 10000, 'kitchen_catalogue_too_large', 503);
        $catalogue = [];
        foreach ($products as $product) {
            $catalogue[(string) $product->id] = ['active' => true, 'category_id' => $product->category_id === null ? null : (int) $product->category_id];
        }
        $staff = [];
        foreach (PosStaff::query()->where('company_id', $a->device->company_id)->where('status', PosStaff::STATUS_ACTIVE)->get() as $person) {
            if (! StaffBranches::staffWorksAt((int) $person->id, (int) $a->device->branch_id)) {
                continue;
            }
            $scopes = ['kitchen.submit', 'kitchen.approve', 'kitchen.handover'];
            try {
                Access::staffAllowed($a->device, (int) $person->id, 'kitchen.complete');
                $scopes = [...$scopes, 'kitchen.complete', 'kitchen.undo'];
            } catch (KitchenFault) {
            }
            $staff[(string) $person->id] = $scopes;
        }
        $configuration = fn (int $version) => DB::table('pos_kv2_configurations')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('version', $version)->first();
        $desired = $configuration((int) $a->branch->desired_version);
        $active = $configuration((int) $a->branch->applied_version) ?? $desired;
        KitchenFault::require($active && $desired, 'configuration_not_found', 409);
        $runtime = ['identity' => $identity, 'host_thumbprint' => $thumbprint, 'host_credential_id' => $claims['jti'], 'mode' => $a->branch->mode, 'activation_state' => $a->branch->activation_state, 'applied_version' => (int) $a->branch->applied_version, 'bundle_hash' => $active->bundle_hash, 'bundle' => Wire::read($active->bundle), 'catalogue' => (object) $catalogue, 'peers' => (object) $peers, 'staff' => (object) $staff];

        return ['runtime' => $runtime, 'desired_runtime' => [...$runtime, 'applied_version' => (int) $desired->version, 'bundle_hash' => $desired->bundle_hash, 'bundle' => Wire::read($desired->bundle)], 'issuer' => config('kitchen.issuer'), 'audience' => config('kitchen.audience'), 'public_keys' => $keys, 'root_certificate' => $root, 'host_grant' => $grant['grant'], 'server_time_ms' => now()->getTimestampMs(), 'refresh_after_seconds' => 300, 'cloud_url' => config('kitchen.cloud_url'), 'device_credential_id' => 'device', 'port' => 18443];
    }

    public function forClient(Access $a): array
    {
        $host = Device::query()->whereKey($a->branch->coordinator_id)->where('company_id', $a->device->company_id)
            ->where('branch_id', $a->device->branch_id)->where('status', 'active')->first();
        $binding = DB::table('pos_kv2_devices')->where('device_id', $host?->id)->where('company_id', $a->device->company_id)
            ->where('branch_id', $a->device->branch_id)->where('enabled', true)->first();
        KitchenFault::require($host && $binding && $binding->assignment === $a->branch->coordinator_assignment
            && $binding->assignment === $host->assignment_activated_at?->toIso8601String(), 'coordinator_unavailable');
        $hostAccess = new Access($host, $a->branch, $binding);
        $identity = $a->identity();
        $hostIdentity = $hostAccess->identity();
        $thumbprint = $hostIdentity['cnf']['x5t#S256'];
        unset($identity['cnf'], $hostIdentity['cnf']);
        $version = (int) $a->branch->applied_version;
        $configuration = $version > 0 ? app(Submissions::class)->config($a, $version) : null;

        return [...$this->trust(), 'identity' => $identity, 'coordinator_identity' => $hostIdentity,
            'coordinator_thumbprint' => $thumbprint, 'is_coordinator' => (int) $host->id === (int) $a->device->id,
            'mode' => $a->branch->mode, 'activation_state' => $a->branch->activation_state,
            'applied_version' => $version, 'desired_version' => (int) $a->branch->desired_version,
            'bundle' => $configuration, 'server_time_ms' => now()->getTimestampMs(),
            'cloud_url' => config('kitchen.cloud_url'), 'port' => 18443];
    }

    private function trust(): array
    {
        $rootPath = config('kitchen.root_certificate_path');
        KitchenFault::require(is_string($rootPath) && is_readable($rootPath), 'kitchen_ca_unconfigured', 503);
        $root = file_get_contents($rootPath);
        KitchenFault::require(openssl_x509_parse($root) !== false, 'kitchen_ca_unconfigured', 503);
        $keys = [];
        foreach (config('kitchen.public_keys', []) as $kid => $pem) {
            $public = openssl_pkey_get_public($pem);
            $key = $public ? openssl_pkey_get_details($public) : false;
            KitchenFault::require($key && ($key['ec']['curve_name'] ?? null) === 'prime256v1', 'kitchen_issuer_unconfigured', 503);
            $keys[$kid] = ['kty' => 'EC', 'crv' => 'P-256', 'x' => $this->base64($key['ec']['x']), 'y' => $this->base64($key['ec']['y'])];
        }
        KitchenFault::require($keys !== [], 'kitchen_issuer_unconfigured', 503);

        return ['root_certificate' => $root, 'public_keys' => $keys,
            'issuer' => config('kitchen.issuer'), 'audience' => config('kitchen.audience')];
    }

    private function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
