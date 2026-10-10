<?php

declare(strict_types=1);

namespace App\Kitchen;

use App\Http\Middleware\RequireTabletStaff;
use App\Models\Device;
use App\Models\PosStaff;
use App\Support\Staff\PositionPermissions;
use App\Support\Staff\StaffBranches;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Access
{
    public function __construct(public readonly Device $device, public readonly object $branch, public readonly object $binding, public readonly ?int $staffId = null, public readonly ?CarbonImmutable $acceptedAt = null) {}

    public static function from(Request $request, string $scope, bool $executor = false): self
    {
        KitchenFault::require((bool) config('kitchen.enabled'), 'kitchen_v2_disabled', 503);
        $d = $request->user();
        KitchenFault::require($d instanceof Device && ($d->isAttended() || $d->device_type === 'kitchen_display'), 'kitchen_device_forbidden', 403);
        KitchenFault::require($d->isAssigned() && $d->status === 'active', 'device_inactive', 403);
        KitchenFault::require(DB::table('pos_branches')->where('id', $d->branch_id)->where('company_id', $d->company_id)->where('status', 'active')->whereNull('deleted_at')->exists(), 'branch_inactive', 403);
        $s = DB::table('pos_kv2_branches')->where('company_id', $d->company_id)->where('branch_id', $d->branch_id)->first();
        $b = DB::table('pos_kv2_devices')->where('device_id', $d->id)->where('company_id', $d->company_id)->where('branch_id', $d->branch_id)->where('enabled', true)->first();
        KitchenFault::require($s && $b && $b->assignment === $d->assignment_activated_at?->toIso8601String(), 'device_not_enrolled', 403);
        KitchenFault::require(in_array($s->mode, ['pending', 'active'], true), 'kitchen_not_active');
        if ($executor) {
            KitchenFault::require((int) $s->coordinator_id === (int) $d->id && $s->coordinator_assignment === $b->assignment, 'executor_forbidden', 403);
        }
        $staff = null;
        if (! in_array($scope, ['configuration', 'delivery', 'snapshot', 'feed', 'host_grant', 'provisioning'], true)) {
            if ($d->isAttended()) {
                $check = RequireTabletStaff::verify($request);
                KitchenFault::require($check['failure'] === null, 'staff_unverified', 403);
                $staff = $check['staff_id'];
            } else {
                KitchenFault::require(in_array($scope, ['kitchen.complete', 'kitchen.undo'], true), 'kds_scope_forbidden', 403);
                $temporary = new self($d, $s, $b);
                $claims = app(Grants::class)->verify((string) $request->header('X-Kitchen-Grant'), $temporary->identity(), $scope);
                KitchenFault::require(is_int($claims['staff_id'] ?? null), 'staff_unverified', 403);
                $staff = $claims['staff_id'];
            }
            self::staffAllowed($d, $staff, $scope);
        }
        if ($d->device_type === 'kitchen_display') {
            KitchenFault::require(in_array($scope, ['snapshot', 'feed', 'provisioning', 'kitchen.complete', 'kitchen.undo'], true), 'kds_scope_forbidden', 403);
        }

        return new self($d, $s, $b, $staff);
    }

    public static function staffAllowed(Device $device, int $staff, string $scope): void
    {
        $row = PosStaff::query()->whereKey($staff)->where('company_id', $device->company_id)->where('status', PosStaff::STATUS_ACTIVE)->first();
        KitchenFault::require($row && StaffBranches::staffWorksAt($staff, (int) $device->branch_id), 'staff_inactive', 403);
        if (in_array($scope, ['kitchen.complete', 'kitchen.undo'], true)) {
            KitchenFault::require(app(PositionPermissions::class)->allows((int) $device->company_id, $row->position, 'kitchen.screen'), 'kitchen_permission_required', 403);
        }
    }

    public function eventTime(): CarbonInterface
    {
        return $this->acceptedAt ?? now();
    }

    public function identity(): array
    {
        return ['company_id' => (int) $this->device->company_id, 'branch_id' => (int) $this->device->branch_id, 'device_id' => (int) $this->device->id, 'assignment' => $this->binding->assignment, 'epoch' => (int) $this->branch->epoch, 'cnf' => ['x5t#S256' => rtrim(strtr(base64_encode(hex2bin($this->binding->certificate_thumbprint)), '+/', '-_'), '=')], 'area_ids' => $this->areas()];
    }

    public function areas(): array
    {
        return Wire::read($this->binding->areas);
    }
}
