<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Device\StaffLoginAction;
use App\Kitchen\Access;
use App\Kitchen\Grants;
use App\Kitchen\KitchenFault;
use App\Kitchen\Runtime;
use App\Support\Staff\PinLockedException;
use Illuminate\Http\Request;
use RuntimeException;

/** No financial session, offline PIN verifier, routing or printer configuration. */
final class KitchenDisplayController
{
    private function access(Request $request): Access
    {
        $a = Access::from($request, 'provisioning');
        KitchenFault::require($a->device->device_type === 'kitchen_display', 'kds_device_required', 403);
        KitchenFault::require($a->areas() !== [], 'kds_areas_required', 403);

        return $a;
    }

    public function connection(Request $request)
    {
        $a = $this->access($request);
        $full = app(Runtime::class)->forClient($a);
        $bundle = $full['bundle'] ?? [];
        $data = array_intersect_key($full, array_flip(['identity', 'coordinator_thumbprint', 'root_certificate', 'public_keys', 'issuer', 'audience', 'mode', 'activation_state', 'applied_version', 'server_time_ms', 'port']));
        $data['areas'] = array_values(array_filter($bundle['areas'] ?? [], fn ($area) => in_array($area['id'], $a->areas(), true)));
        $data['display'] = [...($bundle['display'] ?? ['fallback_minutes' => 15]), 'service_day_utc_offset_minutes' => 240];

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }

    public function session(Request $request)
    {
        $a = $this->access($request);
        KitchenFault::require($request->filled('pin') !== $request->filled('grant'), 'one_credential_required', 422);
        $request->validate(['pin' => 'required_without:grant|string|min:4|max:20', 'grant' => 'required_without:pin|string|max:16384']);
        if ($request->filled('grant')) {
            // Refresh only the existing, still-valid kitchen session. No new
            // operator, area or assignment can be obtained from a cached grant.
            $claims = app(Grants::class)->verify($request->input('grant'), $a->identity(), 'kitchen.complete');
            KitchenFault::require(is_int($claims['staff_id'] ?? null), 'staff_unverified', 403);
            $staff = $claims['staff_id'];
        } else {
            try {
                $staff = (int) app(StaffLoginAction::class)->login($a->device, $request->input('pin'))->id;
            } catch (PinLockedException $e) {
                throw $e;
            } catch (RuntimeException) {
                throw new KitchenFault('invalid_pin', 401);
            }
        }
        Access::staffAllowed($a->device, $staff, 'kitchen.complete');

        return response()->json(['data' => [...app(Grants::class)->issue($a->identity(), ['kitchen.complete', 'kitchen.undo'], $staff), 'server_time_ms' => now()->getTimestampMs()]])->header('Cache-Control', 'no-store, private');
    }
}
