<?php

declare(strict_types=1);

namespace App\Kitchen;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Routing
{
    public static function validate(array $bundle): array
    {
        Validator::make($bundle, [
            'display' => 'sometimes|array:fallback_minutes', 'display.fallback_minutes' => 'required_with:display|integer|between:1,240',
            'rules' => 'present|array|list|max:2000', 'rules.*' => 'array:kind,reference_id,areas,destinations', 'areas.*' => 'array:id,name', 'destinations.*' => 'array:id,name,type,address,port,profile,paused', 'areas' => 'present|array|list|max:100', 'areas.*.id' => 'required|uuid|distinct', 'areas.*.name' => 'required|string|max:100',
            'destinations' => 'present|array|list|max:100', 'destinations.*.id' => 'required|uuid|distinct',
            'destinations.*.type' => ['required', Rule::in(['screen', 'printer'])],
            'destinations.*.name' => 'required|string|max:100', 'destinations.*.address' => 'nullable|ip',
            'destinations.*.port' => 'nullable|integer|between:1,65535', 'destinations.*.profile' => 'nullable|string|max:100',
            'destinations.*.paused' => 'required|boolean',
            'all_items' => 'present|array|list', 'all_items.*' => 'uuid|distinct',
            'fallback' => 'present|array:areas,destinations', 'fallback.areas' => 'present|array|list', 'fallback.destinations' => 'present|array|list',
            'rules.*.kind' => ['required', Rule::in(['item', 'category'])], 'rules.*.reference_id' => 'required|integer|min:1',
            'rules.*.areas' => 'present|array|list|max:100', 'rules.*.areas.*' => 'required|uuid', 'rules.*.destinations' => 'present|array|list|max:100', 'rules.*.destinations.*' => 'required|uuid',
            'fallback.areas.*' => 'required|uuid|distinct', 'fallback.destinations.*' => 'required|uuid|distinct',
        ])->validate();
        foreach ($bundle['rules'] as &$rule) {
            $rule['reference_id'] = (int) $rule['reference_id'];
        }
        unset($rule);
        foreach ($bundle['destinations'] as &$destination) {
            $destination['paused'] = (bool) $destination['paused'];
            if (isset($destination['port'])) {
                $destination['port'] = (int) $destination['port'];
            }
        }
        unset($destination);
        if (isset($bundle['display'])) {
            $bundle['display']['fallback_minutes'] = (int) $bundle['display']['fallback_minutes'];
        }
        $areas = array_column($bundle['areas'], 'id');
        $destinations = array_column($bundle['destinations'], 'id');
        $keys = [];
        foreach ($bundle['rules'] as $rule) {
            $key = $rule['kind'].':'.$rule['reference_id'];
            KitchenFault::require(! isset($keys[$key]), 'duplicate_route', 422);
            $keys[$key] = true;
        }
        foreach ([$bundle['fallback'], ...$bundle['rules']] as $route) {
            KitchenFault::require(array_diff($route['areas'], $areas) === [] && array_diff($route['destinations'], $destinations) === [], 'invalid_route_reference', 422);
        }
        KitchenFault::require(array_diff($bundle['all_items'], $destinations) === [], 'invalid_route_reference', 422);
        foreach ($bundle['destinations'] as $d) {
            KitchenFault::require($d['type'] !== 'printer' || (isset($d['address'],$d['port'],$d['profile'])), 'printer_address_required', 422);
        }

        return $bundle;
    }

    public static function resolve(array $bundle, array $lines): array
    {
        $work = [];
        $copies = [];
        $missing = [];
        foreach ($lines as $line) {
            $item = null;
            $category = null;
            foreach ($bundle['rules'] as $rule) {
                if ($rule['kind'] === 'item' && $rule['reference_id'] === $line['product_id']) {
                    $item = $rule;
                }
                if ($rule['kind'] === 'category' && $rule['reference_id'] === ($line['category_id'] ?? null)) {
                    $category = $rule;
                }
            }
            $route = $item ?? $category ?? ['areas' => [], 'destinations' => []];
            if ($route['areas'] === []) {
                $route = ['areas' => $bundle['fallback']['areas'], 'destinations' => array_merge($route['destinations'], $bundle['fallback']['destinations'])];
            }
            if ($route['areas'] === []) {
                $missing[] = $line['line_uuid'];
            }
            $areaIds = array_values(array_unique($route['areas']));
            sort($areaIds, SORT_STRING);
            foreach ($areaIds as $area) {
                $work[] = ['line_uuid' => $line['line_uuid'], 'area_uuid' => $area, 'quantity' => $line['quantity'], 'line' => $line];
            }
            $ids = array_values(array_unique([...$route['destinations'], ...$bundle['all_items']]));
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $copies[$id][] = $line;
            }
        }
        ksort($copies, SORT_STRING);

        return ['work' => $work, 'copies' => $copies, 'needs_routing' => $missing];
    }
}
