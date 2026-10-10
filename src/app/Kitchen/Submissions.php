<?php

declare(strict_types=1);

namespace App\Kitchen;

use App\Models\SyncEvent;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Submissions
{
    public function submit(Access $a, object $state, array $i, bool $domainResolved = false): array
    {
        KitchenFault::require($state->mode === 'active' && $state->activation_state === 'idle', 'configuration_activation_pending');
        $i = $this->validateIntent($i);
        KitchenFault::require((int) $state->applied_version === $i['policy_version'], 'stale_configuration', 409, ['applied_version' => (int) $state->applied_version]);
        $old = $this->find($a, $i['submission_uuid'], false);
        KitchenFault::require($old === null, 'submission_exists');
        KitchenFault::require($i['revision'] === 1, 'parent_revision_missing');
        KitchenFault::require(! DB::table('pos_kv2_submissions')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('order_uuid', $i['order_uuid'])->where('round_uuid', $i['round_uuid'])->exists(), 'round_already_submitted');
        $order = $this->admit($a, $i, ! $domainResolved);
        $canonicalRound = $i['round_id'] ?? $order?->kitchen_round_id ?? null;
        if ($canonicalRound !== null) {
            KitchenFault::require(! DB::table('pos_kv2_submissions')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('round_id', $canonicalRound)->exists(), 'source_already_submitted');
        }
        $sourceKey = isset($i['round_id']) ? 'round:'.$i['round_id'] : ($order && in_array($i['source'], ['qr_web', 'customer_tablet'], true) ? 'quick:'.$i['order_uuid'] : 'local:'.$i['domain_event_uuid']);
        KitchenFault::require(! DB::table('pos_kv2_submissions')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('source_key', $sourceKey)->exists(), 'source_already_submitted');
        $config = $this->config($a, $i['policy_version']);
        $mode = $config['policies'][in_array($i['source'], ['main_pos', 'handheld'], true) ? 'staff' : $i['source']];
        KitchenFault::require(in_array($mode, ['manual', 'immediate'], true), 'release_mode_unsupported', 422);
        $routes = Routing::resolve($config, $i['lines']);
        $status = $mode === 'manual' ? 'waiting_approval' : ($routes['needs_routing'] === [] ? 'released' : 'needs_routing');
        $id = DB::table('pos_kv2_submissions')->insertGetId(['company_id' => $a->device->company_id, 'branch_id' => $a->device->branch_id, 'uuid' => $i['submission_uuid'], 'source_key' => $sourceKey, 'order_uuid' => $i['order_uuid'], 'round_uuid' => $i['round_uuid'], 'order_id' => $order?->id, 'round_id' => $i['round_id'] ?? $order?->kitchen_round_id ?? null, 'link_state' => $order ? 'linked' : 'pending', 'source' => $i['source'], 'origin_device_id' => $a->device->id, 'origin_assignment' => $a->binding->assignment, 'revision' => 1, 'policy_version' => $i['policy_version'], 'release_mode' => $mode, 'state' => $status, 'released_at' => $status === 'released' ? $a->eventTime() : null, 'context' => Wire::json($i['context']), 'created_at' => now(), 'updated_at' => now()]);
        $this->recordRevision($id, 1, $i, $config, $routes);
        if ($status === 'released') {
            $this->deliver($id, 1, $config, $routes, 'original');
        }

        return $this->receipt($this->find($a, $i['submission_uuid']));
    }

    private function validateIntent(array $i): array
    {
        Validator::make($i, [
            'submission_uuid' => 'required|uuid', 'order_uuid' => 'required|uuid', 'round_uuid' => 'required|uuid', 'revision' => 'required|integer|min:1',
            'policy_version' => 'required|integer|min:1', 'source' => ['required', Rule::in(['main_pos', 'handheld', 'qr_web', 'customer_tablet'])],
            'domain_event_uuid' => 'required|uuid', 'domain_state' => ['required', Rule::in(['submitted'])],
            'round_id' => 'nullable|integer|min:1', 'context' => 'present|array:reference,table,order_type,notes',
            'context.reference' => 'nullable|string|max:100', 'context.table' => 'nullable|string|max:100', 'context.order_type' => 'nullable|string|max:40', 'context.notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:500', 'lines.*' => 'array:line_uuid,product_id,category_id,quantity,name,name_ar,notes,modifiers,removals,allergens,parent_line_uuid,meal,component_snapshot,cooking_minutes',
            'lines.*.line_uuid' => 'required|uuid|distinct', 'lines.*.product_id' => 'required|integer|min:1', 'lines.*.category_id' => 'nullable|integer|min:1',
            'lines.*.quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,8})\.[0-9]{6}$/D', 'not_in:0.000000'],
            'lines.*.cooking_minutes' => 'nullable|integer|between:0,240',
            'lines.*.name' => 'required|string|max:255', 'lines.*.name_ar' => 'nullable|string|max:255', 'lines.*.notes' => 'nullable|string|max:2000',
            'lines.*.modifiers' => 'sometimes|array|max:100', 'lines.*.modifiers.*' => 'string|max:255', 'lines.*.removals' => 'sometimes|array|max:100', 'lines.*.removals.*' => 'string|max:255',
            'lines.*.allergens' => 'sometimes|array|max:100', 'lines.*.allergens.*' => 'string|max:255', 'lines.*.parent_line_uuid' => 'nullable|uuid', 'lines.*.meal' => 'nullable|string|max:255',
            'lines.*.component_snapshot' => 'sometimes|array|max:100',
            'lines.*.component_snapshot.*' => 'array:product_id,quantity,name,name_ar',
            'lines.*.component_snapshot.*.product_id' => 'required|integer|min:1',
            'lines.*.component_snapshot.*.quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,8})\.[0-9]{6}$/D', 'not_in:0.000000'],
            'lines.*.component_snapshot.*.name' => 'required|string|max:255',
            'lines.*.component_snapshot.*.name_ar' => 'nullable|string|max:255',
        ])->validate();
        // Laravel's integer validator accepts numeric strings. Normalize only the
        // validated domain snapshot; Journal continues hashing the original wire
        // event so exact replay/payload-conflict behavior remains unchanged.
        foreach ($i['lines'] as &$line) {
            $line['product_id'] = (int) $line['product_id'];
            if (isset($line['cooking_minutes'])) {
                $line['cooking_minutes'] = (int) $line['cooking_minutes'];
            }
            if (isset($line['category_id'])) {
                $line['category_id'] = (int) $line['category_id'];
            }
            foreach ($line['component_snapshot'] ?? [] as $index => $component) {
                $line['component_snapshot'][$index]['product_id'] = (int) $component['product_id'];
            }
        }
        unset($line);

        return $i;
    }

    private function admit(Access $a, array $i, bool $checkSource = true): ?object
    {
        $order = DB::table('pos_orders')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('uuid', $i['order_uuid'])->lockForUpdate()->first();
        $staff = in_array($i['source'], ['main_pos', 'handheld'], true);
        // Finance can arrive before the LAN journal, and table sync can map a
        // local order UUID onto an existing shared bill. Resolve through the
        // ORIGINAL device event, never by a later payment/station reference.
        if ($staff) {
            $origin = $checkSource ? null : $this->find($a, $i['submission_uuid'], false);
            $deviceId = $origin?->origin_device_id ?? $a->device->id;
            $financial = SyncEvent::query()->where('device_id', $deviceId)
                ->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)
                ->where('client_event_id', $i['domain_event_uuid'])->first();
            if ($financial) {
                $payload = $financial->payload_json ?? [];
                $originalUuid = $payload['order']['uuid'] ?? $payload['order_uuid'] ?? null;
                if ($originalUuid === null && $financial->event_type === 'table.session.round' && is_string($payload['seating_key'] ?? null)) {
                    $originalUuid = Ids::stable('staff-order:'.$a->device->company_id.':'.$a->device->branch_id.':'.$deviceId.':'.$payload['seating_key']);
                }
                KitchenFault::require(in_array($financial->event_type, ['order.create', 'table.session.round'], true)
                    && $originalUuid === $i['order_uuid'], 'domain_identity_mismatch', 422);
                KitchenFault::require($financial->ack_status === SyncEvent::STATUS_PROCESSED, 'domain_hold', 422);
                KitchenFault::require($financial->server_received_at !== null && $financial->server_received_at->gte(CarbonImmutable::parse($origin?->origin_assignment ?? $a->binding->assignment)), 'domain_assignment_mismatch', 403);
                $result = $financial->result_json ?? [];
                KitchenFault::require(! ($result['needs_review'] ?? false), 'domain_hold', 422);
                if (isset($result['order_id']) || isset($result['order_uuid'])) {
                    $order = DB::table('pos_orders')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)
                        ->when(isset($result['order_id']), fn ($q) => $q->where('id', $result['order_id']), fn ($q) => $q->where('uuid', $result['order_uuid']))->lockForUpdate()->first();
                    KitchenFault::require($order !== null, 'order_link_pending');
                    if (isset($result['round_id'])) {
                        $i['round_id'] = (int) $result['round_id'];
                        $order->kitchen_round_id = $i['round_id'];
                    }
                }
            }
        }
        if ($staff && $checkSource) {
            KitchenFault::require($i['source'] === ($a->device->device_type === 'handheld' ? 'handheld' : 'main_pos'), 'source_device_mismatch', 403);
        } elseif (! $staff) {
            KitchenFault::require($order !== null, 'cloud_order_required', 422);
        }
        if ($order) {
            KitchenFault::require(! in_array($order->status, ['void', 'refunded', 'combined'], true), 'order_not_eligible', 422);
            if (! isset($i['round_id'])) {
                KitchenFault::require($order->source === $i['source'], 'order_not_eligible', 422);
            }
            if ($staff && ! isset($i['round_id'])) {
                KitchenFault::require(! in_array($order->status, ['held'], true), 'domain_hold', 422);
            }
            if (isset($i['round_id'])) {
                $round = DB::table('pos_qr_order_rounds')->where('id', $i['round_id'])->where('order_id', $order->id)->first();
                KitchenFault::require($round && in_array($round->status, ['accepted', 'pending_confirmation'], true), 'round_not_eligible', 422);
                KitchenFault::require(RoundAdmission::source($order, $round) === $i['source'], 'round_source_mismatch', 422);
                $priced = RoundAdmission::lines($round);
                // A persisted cloud round is the authoritative set: quantities/products cannot be invented by the caller.
                $this->matchPriced($priced, $i['lines']);
            } elseif (! $staff) {
                KitchenFault::require(in_array($order->order_type, ['quick', 'takeaway', 'to_go'], true), 'round_required', 422);
                // A quick customer's original submission is separate from every
                // later staff addition, even while its approval is pending.
                $added = [];
                foreach (DB::table('pos_qr_order_rounds')->where('order_id', $order->id)->whereNull('qr_session_id')->whereNull('table_session_id')
                    ->whereNotIn('id', DB::table('pos_tablet_orders')->select('round_id')->where('order_id', $order->id)->whereNotNull('round_id'))->get() as $later) {
                    foreach (Wire::read($later->priced_lines) as $line) {
                        if (isset($line['order_item_id'])) {
                            $added[] = (int) $line['order_item_id'];
                        }
                    }
                }
                $priced = DB::table('pos_order_items')->where('order_id', $order->id)->whereNotNull('product_id')->where('status', '!=', 'void')
                    ->whereNotIn('id', $added)->where(fn ($q) => $q->whereNull('parent_order_item_id')->orWhereNotIn('parent_order_item_id', $added))
                    ->get()->map(fn ($l) => ['product_id' => (int) $l->product_id, 'qty' => $l->qty])->all();
                $this->matchPriced($priced, $i['lines']);
            }
        }
        $lineIds = array_column($i['lines'], 'line_uuid');
        foreach ($i['lines'] as $line) {
            $product = Catalogue::query((int) $a->device->company_id, (int) $a->device->branch_id)->where('id', $line['product_id'])->first();
            KitchenFault::require($product && $product->status === 'active', 'catalogue_hold', 422);
            KitchenFault::require(($line['category_id'] ?? null) === ($product->category_id === null ? null : (int) $product->category_id), 'category_mismatch', 422);
            KitchenFault::require(! isset($line['parent_line_uuid']) || ($line['parent_line_uuid'] !== $line['line_uuid'] && in_array($line['parent_line_uuid'], $lineIds, true)), 'parent_line_missing', 422);
        }

        return $order;
    }

    private function matchPriced(array $priced, array $lines): void
    {
        $normalize = static function (array $items, string $qty): array {
            $totals = [];
            foreach ($items as $line) {
                $key = (int) $line['product_id'];
                $amount = BigDecimal::of((string) $line[$qty])->toScale(6);
                $totals[$key] = isset($totals[$key]) ? $totals[$key]->plus($amount) : $amount;
            }
            ksort($totals);

            return array_map(fn ($v) => (string) $v, $totals);
        };
        $expanded = [];
        foreach ($priced as $line) {
            $line['qty'] = (string) BigDecimal::of((string) $line['qty'])->minus((string) ($line['cancelled_qty'] ?? 0));
            if (BigDecimal::of($line['qty'])->isZero()) {
                continue;
            }
            $expanded[] = $line;
            foreach ($line['components'] ?? [] as $component) {
                $expanded[] = [...$component, 'qty' => (string) BigDecimal::of((string) $line['qty'])->multipliedBy((string) $component['qty'])];
            }
        }
        KitchenFault::require($normalize($expanded, 'qty') === $normalize($lines, 'quantity'), 'order_lines_mismatch', 422);
    }

    public function find(Access $a, string $uuid, bool $required = true): ?object
    {
        $s = DB::table('pos_kv2_submissions')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('uuid', $uuid)->first();
        if ($required) {
            KitchenFault::require($s !== null, 'submission_not_found', 404);
        }

        return $s;
    }

    public function config(Access $a, int $version): array
    {
        $c = DB::table('pos_kv2_configurations')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('version', $version)->first();
        KitchenFault::require($c !== null, 'configuration_not_found', 404);

        return Wire::read($c->bundle);
    }

    private function recordRevision(int $id, int $revision, array $i, array $config, array $routes): void
    {
        $snapshot = ['intent' => $i, 'configuration' => $config, 'routing' => $routes];
        DB::table('pos_kv2_revisions')->insert(['submission_id' => $id, 'revision' => $revision, 'snapshot' => Wire::json($snapshot), 'payload_hash' => Wire::hash($snapshot), 'created_at' => now()]);
        foreach ($routes['work'] as $w) {
            DB::table('pos_kv2_work')->insert(['submission_id' => $id, 'revision' => $revision, 'line_uuid' => $w['line_uuid'], 'area_uuid' => $w['area_uuid'], 'quantity' => $w['quantity'], 'line' => Wire::json($w['line']), 'state' => 'outstanding']);
        }
    }

    public function deliver(int $id, int $revision, array $config, array $routes, string $purpose): void
    {
        foreach ($routes['copies'] as $dest => $lines) {
            $destination = collect($config['destinations'])->firstWhere('id', $dest);
            DB::table('pos_kv2_deliveries')->insert(['uuid' => Ids::stable('delivery:'.DB::table('pos_kv2_submissions')->where('id', $id)->value('uuid').":$revision:$dest:$purpose:"), 'submission_id' => $id, 'revision' => $revision, 'destination_uuid' => $dest, 'purpose' => $purpose, 'snapshot' => Wire::json([...$this->deliveryIdentity($id, $revision), 'destination' => $destination, 'lines' => $lines]), 'state' => $destination['paused'] ? 'paused' : 'queued', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function event(Access $a, object $state, array $i, bool $domainResolved = false): array
    {
        Validator::make($i, ['submission_uuid' => 'required|uuid', 'expected_revision' => 'required|integer|min:1'])->validate();
        $s = $this->find($a, $i['submission_uuid']);
        KitchenFault::require((int) $s->revision === $i['expected_revision'], 'stale_revision', 409, ['revision' => (int) $s->revision]);
        $rev = DB::table('pos_kv2_revisions')->where('submission_id', $s->id)->where('revision', $s->revision)->first();
        $snapshot = Wire::read($rev->snapshot);
        KitchenFault::require($domainResolved || $s->link_state !== 'review_required', 'domain_review_required');
        switch ($i['action']) {
            case 'approve':
                KitchenFault::require($s->state === 'waiting_approval', 'approval_not_pending');
                if (! $domainResolved) {
                    app(CloudIntake::class)->confirm($a, $snapshot['intent']);
                }
                $this->admit($a, $snapshot['intent'], false);
                $status = $snapshot['routing']['needs_routing'] === [] ? 'released' : 'needs_routing';
                DB::table('pos_kv2_submissions')->where('id', $s->id)->update(['state' => $status, 'released_at' => $status === 'released' ? $a->eventTime() : null, 'updated_at' => now()]);
                if ($status === 'released') {
                    $this->deliver($s->id, $s->revision, $snapshot['configuration'], $snapshot['routing'], 'original');
                }break;
            case 'reject':
                KitchenFault::require($s->state === 'waiting_approval', 'approval_not_pending');
                DB::table('pos_kv2_submissions')->where('id', $s->id)->update(['state' => 'rejected', 'updated_at' => now()]);
                break;
            case 'item_done': case 'done_all': case 'undo':
                KitchenFault::require(in_array($s->state, ['released', 'ready'], true) && ! $s->handed_over_at, 'work_not_active');
                Validator::make($i, ['area_uuid' => 'required|uuid', 'line_uuid' => $i['action'] === 'done_all' ? 'nullable|uuid' : 'required|uuid'])->validate();
                KitchenFault::require(in_array($i['area_uuid'], $a->areas(), true), 'area_forbidden', 403);
                $q = DB::table('pos_kv2_work')->where('submission_id', $s->id)->where('revision', $s->revision)->where('area_uuid', $i['area_uuid']);
                if ($i['action'] !== 'done_all') {
                    $q->where('line_uuid', $i['line_uuid']);
                }
                KitchenFault::require((clone $q)->exists(), 'work_not_found', 404);
                if ($i['action'] === 'undo') {
                    $q->where('state', 'done')->update(['state' => 'outstanding', 'done_at' => null, 'done_by' => null]);
                } else {
                    $q->where('state', 'outstanding')->update(['state' => 'done', 'done_at' => $a->eventTime(), 'done_by' => $a->staffId]);
                }
                $this->readiness($s, $i['event_id'], $a->eventTime());
                break;
            case 'served': case 'collected':
                KitchenFault::require($s->state === 'ready' && $s->ready_cycle === ($i['ready_cycle'] ?? null) && ! $s->handed_over_at, 'ready_cycle_conflict');
                DB::table('pos_kv2_submissions')->where('id', $s->id)->update(['state' => $i['action'], 'handover_kind' => $i['action'], 'handed_over_at' => $a->eventTime(), 'updated_at' => now()]);
                break;
            case 'cancel':
                KitchenFault::require(! $s->handed_over_at && ! in_array($s->state, ['cancelled', 'rejected'], true), 'cancellation_review_required');
                $this->supersedeDeliveries($s, [], $i['event_id']);
                DB::table('pos_kv2_submissions')->where('id', $s->id)->update(['state' => 'cancelled', 'ready_at' => null, 'ready_cycle' => null, 'updated_at' => now()]);
                break;
            case 'amend':
                KitchenFault::require(! $s->handed_over_at && ! in_array($s->state, ['cancelled', 'rejected'], true), 'new_round_required');
                if (! $domainResolved) {
                    KitchenFault::require($state->mode === 'active' && $state->activation_state === 'idle', 'configuration_activation_pending');
                }
                $replacement = $i['replacement'] ?? [];
                KitchenFault::require(array_diff(array_keys($replacement), ['lines', 'context', 'policy_version']) === [], 'amend_identity_conflict', 422);
                $newRevision = $s->revision + 1;
                $next = [...$snapshot['intent'], ...$replacement, 'revision' => $newRevision];
                $next = $this->validateIntent($next);
                if (! $domainResolved) {
                    KitchenFault::require((int) $state->applied_version === $next['policy_version'], 'stale_configuration');
                    $this->admit($a, $next, false);
                }
                $config = $this->config($a, $next['policy_version']);
                $routes = Routing::resolve($config, $next['lines']);
                $newSnapshot = ['intent' => $next, 'configuration' => $config, 'routing' => $routes];
                $this->recordRevision($s->id, $newRevision, $next, $config, $routes);
                // Preserve Done only for byte-identical line/area work, never for changed quantity or modifiers.
                foreach (DB::table('pos_kv2_work')->where('submission_id', $s->id)->where('revision', $newRevision)->get() as $w) {
                    $old = DB::table('pos_kv2_work')->where('submission_id', $s->id)->where('revision', $s->revision)->where('line_uuid', $w->line_uuid)->where('area_uuid', $w->area_uuid)->first();
                    if ($old && $old->state === 'done' && Wire::hash(Wire::read($old->line)) === Wire::hash(Wire::read($w->line))) {
                        DB::table('pos_kv2_work')->where('id', $w->id)->update(['state' => 'done', 'done_at' => $old->done_at, 'done_by' => $old->done_by]);
                    }
                }
                // Already released work stays released; a prior waiting approval never gets mass-approved by new settings.
                $status = $s->released_at ? ($newSnapshot['routing']['needs_routing'] === [] ? 'released' : 'needs_routing') : ($s->state === 'waiting_approval' ? 'waiting_approval' : ($routes['needs_routing'] === [] ? 'released' : 'needs_routing'));
                $this->supersedeDeliveries($s, $status === 'released' ? $newSnapshot : [], $i['event_id'], $newRevision);
                DB::table('pos_kv2_submissions')->where('id', $s->id)->update(['revision' => $newRevision, 'context' => Wire::json($next['context']), 'policy_version' => $next['policy_version'], 'state' => $status, 'released_at' => $s->released_at ?? ($status === 'released' ? $a->eventTime() : null), 'ready_at' => null, 'ready_cycle' => null, 'updated_at' => now()]);
                if ($status === 'released') {
                    $this->readiness($this->find($a, $s->uuid), $i['event_id'], $a->eventTime());
                }break;
            case 'link':
                $order = DB::table('pos_orders')->where('company_id', $s->company_id)->where('branch_id', $s->branch_id)->where('uuid', $s->order_uuid)->first();
                KitchenFault::require($order && $order->source === $s->source, 'order_link_pending');
                KitchenFault::require($s->order_id === null || (int) $s->order_id === (int) $order->id, 'order_link_conflict');
                DB::table('pos_kv2_submissions')->where('id', $s->id)->update(['order_id' => $order->id, 'link_state' => 'linked', 'updated_at' => now()]);
                break;
            default:throw new KitchenFault('action_unsupported', 422);
        }

        return $this->receipt($this->find($a, $s->uuid));
    }

    /** A delivery is self-contained, including cancelled lines and the kitchen order context. */
    private function deliveryIdentity(int $id, int $revision): array
    {
        $row = DB::table('pos_kv2_revisions')->where('submission_id', $id)->where('revision', $revision)->first();
        $intent = Wire::read($row->snapshot)['intent'];

        return ['submission_uuid' => $intent['submission_uuid'], 'order_uuid' => $intent['order_uuid'],
            'round_uuid' => $intent['round_uuid'], 'source' => $intent['source'],
            'context' => $intent['context'], 'revision' => $revision];
    }

    private function supersedeDeliveries(object $s, array $replacement, string $eventId, int $revision = 0): void
    {
        $revision = $revision ?: $s->revision;
        $oldCopies = [];
        // Fold the complete possibly-sent history, not only the last revision's delta.
        // Cancel every known-unsent attempt first, including copies from older revisions.
        foreach (DB::table('pos_kv2_deliveries')->where('submission_id', $s->id)->orderBy('id')->get() as $d) {
            if (in_array($d->state, ['queued', 'paused', 'claimed', 'failed_before_send'], true)) {
                DB::table('pos_kv2_deliveries')->where('id', $d->id)->update(['state' => 'cancelled', 'updated_at' => now()]);
                DB::table('pos_kv2_attempts')->where('delivery_id', $d->id)->where('state', 'claimed')->update(['state' => 'cancelled', 'updated_at' => now()]);
            } elseif ($d->state !== 'cancelled' && $d->purpose !== 'reprint') {
                $snapshot = Wire::read($d->snapshot);
                $copy = $oldCopies[$d->destination_uuid] ?? ['destination' => $snapshot['destination'], 'lines' => [], 'context' => []];
                $byId = array_column($copy['lines'], null, 'line_uuid');
                foreach ($snapshot['removed_line_uuids'] ?? [] as $removed) {
                    unset($byId[$removed]);
                }
                foreach ($snapshot['lines'] as $line) {
                    $byId[$line['line_uuid']] = $line;
                }
                $oldCopies[$d->destination_uuid] = ['destination' => $snapshot['destination'], 'lines' => array_values($byId), 'context' => $snapshot['context'] ?? $copy['context']];
            }
        }
        $copies = $replacement['routing']['copies'] ?? [];
        $destinations = $replacement['configuration']['destinations'] ?? [];
        $identity = $this->deliveryIdentity($s->id, $revision);
        foreach (array_unique([...array_keys($oldCopies), ...array_keys($copies)]) as $id) {
            $before = $oldCopies[$id]['lines'] ?? [];
            $after = $copies[$id] ?? [];
            $beforeBy = array_column($before, null, 'line_uuid');
            $afterBy = array_column($after, null, 'line_uuid');
            $changed = array_values(array_filter($after, fn ($l) => ! isset($beforeBy[$l['line_uuid']]) || Wire::hash($beforeBy[$l['line_uuid']]) !== Wire::hash($l)));
            $removed = array_values(array_diff(array_keys($beforeBy), array_keys($afterBy)));
            $contextChanged = $before !== [] && Wire::hash($oldCopies[$id]['context']) !== Wire::hash($identity['context']);
            if ($changed === [] && $removed === [] && ! $contextChanged) {
                continue;
            }
            $dest = $oldCopies[$id]['destination'] ?? collect($destinations)->firstWhere('id', $id);
            $purpose = $before === [] ? 'original' : ($after === [] ? 'cancel' : 'change');
            $snapshot = [...$identity, 'destination' => $dest, 'lines' => $changed,
                'removed_line_uuids' => $removed, 'removed_lines' => array_values(array_intersect_key($beforeBy, array_flip($removed))),
                'previous_context' => $oldCopies[$id]['context'] ?? null, 'context_changed' => $contextChanged,
                'previous_revision' => (int) $s->revision];
            DB::table('pos_kv2_deliveries')->insert(['uuid' => Ids::stable("delivery:{$s->uuid}:$revision:$id:$purpose:$eventId"), 'submission_id' => $s->id, 'revision' => $revision, 'destination_uuid' => $id, 'purpose' => $purpose, 'copy_key' => $eventId, 'snapshot' => Wire::json($snapshot), 'state' => $dest['paused'] ? 'paused' : 'queued', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function readiness(object $s, string $eventId, CarbonInterface $acceptedAt): void
    {
        $work = DB::table('pos_kv2_work')->where('submission_id', $s->id)->where('revision', $s->revision);
        $ready = (clone $work)->exists() && ! (clone $work)->where('state', 'outstanding')->exists();
        DB::table('pos_kv2_submissions')->where('id', $s->id)->update(['state' => $ready ? 'ready' : 'released', 'ready_at' => $ready ? ($s->ready_at ?? $acceptedAt) : null, 'ready_cycle' => $ready ? ($s->ready_cycle ?? Ids::stable('ready:'.$eventId)) : null, 'updated_at' => now()]);
    }

    public function receipt(object $s): array
    {
        return ['submission_uuid' => $s->uuid, 'revision' => (int) $s->revision, 'state' => $s->state, 'link_state' => $s->link_state, 'ready_cycle' => $s->ready_cycle];
    }
}
