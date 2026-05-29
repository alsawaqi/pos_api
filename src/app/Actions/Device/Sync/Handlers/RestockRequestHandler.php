<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\RestockRequest;
use App\Models\RestockRequestLine;
use App\Models\SyncEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 8.8 — processes a `restock.request` sync event into a
 * pos_restock_requests header + lines (blueprint §5.6.4/§5.6.5).
 *
 * Lands status=submitted (awaiting the merchant portal's review). Each line's
 * ingredient is resolved scoped to the device's company (cross-tenant or
 * unknown ingredient → the event fails), and unit_at_set is snapshotted from
 * it. A duplicate ingredient in one request trips the (request, ingredient)
 * unique index and fails the event — duplicates are a client bug per §10.5.
 */
class RestockRequestHandler implements SyncEventHandler
{
    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;

        $validator = Validator::make($payload, [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['sometimes', 'nullable', 'string'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('invalid restock.request payload: '.implode('; ', $validator->errors()->all()));
        }

        return DB::transaction(function () use ($payload, $device): array {
            $request = RestockRequest::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'status' => RestockRequest::STATUS_SUBMITTED,
                'submitted_at' => isset($payload['requested_at']) ? Carbon::parse((string) $payload['requested_at']) : now(),
                'requested_by_user_id' => null,
                'note' => $payload['note'] ?? null,
            ]);

            $sort = 0;
            foreach ($payload['lines'] as $line) {
                $ingredient = Ingredient::query()
                    ->where('company_id', $device->company_id)
                    ->find((int) $line['ingredient_id']);
                if ($ingredient === null) {
                    throw new RuntimeException('unknown ingredient in restock.request: '.$line['ingredient_id']);
                }

                RestockRequestLine::create([
                    'restock_request_id' => $request->id,
                    'ingredient_id' => (int) $line['ingredient_id'],
                    'quantity_requested' => $line['quantity'],
                    'quantity_allocated' => 0,
                    'unit_at_set' => $ingredient->unit,
                    'sort_order' => $sort++,
                ]);
            }

            return ['restock_request_id' => (int) $request->id, 'status' => 'submitted', 'lines' => count($payload['lines'])];
        });
    }
}
