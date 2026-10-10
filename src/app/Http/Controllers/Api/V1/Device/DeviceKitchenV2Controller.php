<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Kitchen\Access;
use App\Kitchen\Certificates;
use App\Kitchen\Deliveries;
use App\Kitchen\Grants;
use App\Kitchen\Journal;
use App\Kitchen\KitchenFault;
use App\Kitchen\Recovery;
use App\Kitchen\Runtime;
use App\Kitchen\Submissions;
use App\Kitchen\Wire;
use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class DeviceKitchenV2Controller
{
    public function __construct(private readonly Journal $journal, private readonly Submissions $submissions, private readonly Deliveries $deliveries) {}

    private function input(Request $request): array
    {
        KitchenFault::require(strlen($request->getContent()) <= 1048576, 'kitchen_payload_too_large', 413);
        $input = $request->all();
        $allowed = ['protocol_version', 'event_id', 'epoch', 'occurred_at', 'action', 'submission_uuid', 'order_uuid', 'round_uuid', 'revision', 'policy_version', 'source', 'domain_event_uuid', 'domain_state', 'round_id', 'context', 'lines', 'expected_revision', 'area_uuid', 'line_uuid', 'ready_cycle', 'replacement', 'delivery_uuid', 'destination_uuid', 'attempt_uuid', 'result', 'evidence', 'reason', 'version', 'hash', 'activation_id'];
        KitchenFault::require(array_diff(array_keys($input), $allowed) === [], 'unknown_kitchen_field', 422);

        return $input;
    }

    public function recoveryArchive(Request $request)
    {
        KitchenFault::require(strlen($request->getContent()) <= 10 * 1048576, 'kitchen_payload_too_large', 413);

        return response()->json(['data' => app(Recovery::class)->archive(Access::from($request, 'kitchen.recovery', true), $request->all())])->header('Cache-Control', 'no-store, private');
    }

    public function recoveryRead(Request $request, string $requestId)
    {
        return response()->json(['data' => app(Recovery::class)->read(Access::from($request, 'kitchen.recovery'), $requestId)])->header('Cache-Control', 'no-store, private');
    }

    public function certificate(Request $request)
    {
        KitchenFault::require(strlen($request->getContent()) <= 40000, 'kitchen_payload_too_large', 413);

        return response()->json(['data' => app(Certificates::class)->issue(Access::from($request, 'provisioning'), $request->all())]);
    }

    public function connection(Request $request)
    {
        return response()->json(['data' => app(Runtime::class)->forClient(Access::from($request, 'configuration'))])
            ->header('Cache-Control', 'no-store, private');
    }

    public function runtime(Request $request)
    {
        return response()->json(['data' => app(Runtime::class)->forHost(Access::from($request, 'configuration', true))])->header('Cache-Control', 'no-store, private');
    }

    public function configuration(Request $request)
    {
        $a = Access::from($request, 'configuration', true);
        $s = $a->branch;
        $c = DB::table('pos_kv2_configurations')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('version', $s->desired_version)->first();

        return response()->json(['data' => ['epoch' => (int) $s->epoch, 'mode' => $s->mode, 'desired_version' => (int) $s->desired_version, 'applied_version' => (int) $s->applied_version, 'staged_version' => $s->staged_version, 'activation_id' => $s->activation_id, 'activation_state' => $s->activation_state, 'desired' => $c ? ['hash' => $c->bundle_hash, 'bundle' => Wire::read($c->bundle)] : null]]);
    }

    public function acknowledge(Request $request)
    {
        $i = $this->input($request);
        $a = Access::from($request, 'configuration', true);
        Validator::make($i, ['action' => 'required|in:prepare_configuration,commit_configuration,finish_configuration', 'version' => 'required|integer|min:1', 'hash' => 'required|string|size:64', 'activation_id' => 'required|uuid'])->validate();

        return response()->json(['data' => $this->journal->apply($a, $i, function ($s) use ($a, $i) {
            $c = DB::table('pos_kv2_configurations')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('version', $i['version'])->first();
            KitchenFault::require($c && hash_equals($c->bundle_hash, $i['hash']), 'configuration_hash_mismatch');
            switch ($i['action']) {
                case 'prepare_configuration':
                    KitchenFault::require($s->activation_state === 'idle' && (int) $s->desired_version === $i['version'], 'configuration_changed');
                    DB::table('pos_kv2_branches')->where('id', $s->id)->update(['staged_version' => $i['version'], 'activation_id' => $i['activation_id'], 'activation_state' => 'prepared', 'updated_at' => now()]);
                    break;
                case 'commit_configuration':
                    KitchenFault::require($s->activation_state === 'prepared' && (int) $s->staged_version === $i['version'] && $s->activation_id === $i['activation_id'], 'activation_conflict');
                    DB::table('pos_kv2_branches')->where('id', $s->id)->update(['applied_version' => $i['version'], 'activation_state' => 'committed', 'updated_at' => now()]);
                    break;
                case 'finish_configuration':
                    KitchenFault::require($s->activation_state === 'committed' && (int) $s->applied_version === $i['version'] && $s->activation_id === $i['activation_id'], 'activation_conflict');
                    DB::table('pos_kv2_branches')->where('id', $s->id)->update(['activation_state' => 'idle', 'mode' => 'active', 'staged_version' => null, 'updated_at' => now()]);
                    break;
            }

            return ['activation_id' => $i['activation_id'], 'version' => $i['version'], 'phase' => $i['action']];
        })]);
    }

    public function submit(Request $request)
    {
        $i = $this->input($request);
        KitchenFault::require(($i['action'] ?? null) === 'submit', 'action_unsupported', 422);
        $a = Access::from($request, 'kitchen.submit');

        return response()->json(['data' => $this->journal->apply($a, $i, fn ($s) => $this->submissions->submit($a, $s, $i))], 201);
    }

    public function event(Request $request)
    {
        $i = $this->input($request);
        $action = $i['action'] ?? '';
        $scope = match ($action) {
            'item_done','done_all' => 'kitchen.complete','undo' => 'kitchen.undo','served','collected' => 'kitchen.handover','approve','reject','amend','cancel','link' => 'kitchen.approve',default => throw new KitchenFault('action_unsupported', 422)
        };
        $a = Access::from($request, $scope);

        return response()->json(['data' => $this->journal->apply($a, $i, fn ($s) => $this->submissions->event($a, $s, $i))]);
    }

    public function deliveryEvent(Request $request)
    {
        $i = $this->input($request);
        $scope = in_array($i['action'] ?? '', ['reprint', 'resume_delivery', 'reassign_delivery'], true) ? 'kitchen.approve' : 'delivery';
        $a = Access::from($request, $scope, true);

        return response()->json(['data' => $this->journal->apply($a, $i, fn () => $this->deliveries->apply($a, $i))]);
    }

    public function deliveries(Request $request)
    {
        $a = Access::from($request, 'delivery', true);
        $after = $this->cursor($request);
        $rows = $this->deliveries->query($a)->where('id', '>', $after)->orderBy('id')->limit(100)->get();

        return response()->json(['data' => ['deliveries' => $rows->map(fn ($d) => ['cursor' => (int) $d->id, 'delivery_uuid' => $d->uuid, 'revision' => (int) $d->revision, 'purpose' => $d->purpose, 'state' => $d->state, 'attempt_uuid' => $d->attempt_uuid, 'snapshot' => Wire::read($d->snapshot)]), 'next_cursor' => $rows->last()?->id ?? $after, 'has_more' => $rows->count() === 100]]);
    }

    public function snapshot(Request $request)
    {
        $a = Access::from($request, 'snapshot');
        $after = $this->cursor($request);
        $q = DB::table('pos_kv2_submissions')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('id', '>', $after);
        $view = $request->query('view', 'active');
        KitchenFault::require(in_array($view, ['active', 'ready', 'recent', 'pending'], true), 'invalid_view', 422);
        $q->whereIn('state', match ($view) {
            'active' => ['released', 'ready'],'ready' => ['ready'],'pending' => ['waiting_approval', 'needs_routing'],'recent' => ['served', 'collected', 'cancelled', 'rejected']
        });
        if ($a->device->device_type === 'kitchen_display') {
            KitchenFault::require($view !== 'pending', 'kds_scope_forbidden', 403);
            $q->whereExists(fn ($w) => $w->selectRaw('1')->from('pos_kv2_work')->whereColumn('submission_id', 'pos_kv2_submissions.id')->whereColumn('revision', 'pos_kv2_submissions.revision')->whereIn('area_uuid', $a->areas()));
        }
        $rows = $q->orderBy('id')->limit(100)->get();
        $cards = [];
        foreach ($rows as $s) {
            $work = DB::table('pos_kv2_work')->where('submission_id', $s->id)->where('revision', $s->revision);
            if ($a->device->device_type === 'kitchen_display') {
                $work->whereIn('area_uuid', $a->areas());
            }
            $cards[] = [...$this->submissions->receipt($s), 'cursor' => (int) $s->id, 'order_uuid' => $s->order_uuid, 'round_uuid' => $s->round_uuid, 'context' => Wire::read($s->context), 'released_at' => $s->released_at, 'ready_at' => $s->ready_at, 'handed_over_at' => $s->handed_over_at, 'work' => $work->get()->map(fn ($w) => ['line_uuid' => $w->line_uuid, 'area_uuid' => $w->area_uuid, 'quantity' => $w->quantity, 'line' => Wire::read($w->line), 'state' => $w->state, 'done_at' => $w->done_at])->all()];
        }

        return response()->json(['data' => ['cards' => $cards, 'next_cursor' => $rows->last()?->id ?? $after, 'has_more' => $rows->count() === 100, 'event_cursor' => (int) $a->branch->sequence]]);
    }

    public function feed(Request $request)
    {
        $a = Access::from($request, 'feed');
        $after = $this->cursor($request);
        $rows = DB::table('pos_kv2_events')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('sequence', '>', $after)->orderBy('sequence')->limit(100)->get();
        $events = [];
        foreach ($rows as $e) {
            $result = Wire::read($e->result);
            if ($a->device->device_type === 'kitchen_display') {
                $s = isset($result['submission_uuid']) ? $this->submissions->find($a, $result['submission_uuid'], false) : null;
                if (! $s || ! DB::table('pos_kv2_work')->where('submission_id', $s->id)->whereIn('area_uuid', $a->areas())->exists()) {
                    continue;
                }
            }
            // Invalidation feed, not a leak of submitted identity/configuration/customer records.
            $events[] = ['event_id' => $e->uuid, 'sequence' => (int) $e->sequence, 'action' => $e->action, 'submission_uuid' => $result['submission_uuid'] ?? null, 'recorded_at' => $e->recorded_at];
        }

        return response()->json(['data' => ['events' => $events, 'next_cursor' => $rows->last()?->sequence ?? $after, 'has_more' => $rows->count() === 100]]);
    }

    /** Full journal only for the assigned coordinator, through authenticated HTTPS. */
    public function intake(Request $request)
    {
        $a = Access::from($request, 'feed', true);
        $after = $this->cursor($request);
        $rows = DB::table('pos_kv2_events')->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)
            ->where('sequence', '>', $after)->orderBy('sequence')->limit(25)->get();
        $entries = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $input = Wire::read($row->payload);
            $receipt = Wire::read($row->result);
            $configuration = null;
            $policyVersion = null;
            // Old epochs and K3 receipts without immutable actor metadata remain
            // historical. They cannot be activated by downloading a fresh feed.
            $executable = (int) $row->epoch === (int) $a->branch->epoch && isset($receipt['actor']);
            $packet = ['cursor' => (int) $row->sequence, 'historical' => ! $executable];
            if ($executable) {
                if (in_array($row->action, ['submit', 'amend'], true)) {
                    $submission = DB::table('pos_kv2_submissions')->where('company_id', $a->device->company_id)
                        ->where('branch_id', $a->device->branch_id)->where('uuid', $input['submission_uuid'])->first();
                    $revision = $row->action === 'submit' ? 1 : $input['expected_revision'] + 1;
                    $frozen = $submission ? DB::table('pos_kv2_revisions')->where('submission_id', $submission->id)
                        ->where('revision', $revision)->first() : null;
                    KitchenFault::require($frozen !== null, 'cloud_revision_missing', 503);
                    $snapshot = Wire::read($frozen->snapshot);
                    $configuration = $snapshot['configuration'];
                    $policyVersion = $snapshot['intent']['policy_version'];
                }
                $identity = $a->identity();
                unset($identity['cnf']);
                $packet += ['identity' => $identity, 'event' => $input, 'origin' => $receipt['actor'],
                    'payload_hash' => $row->payload_hash, 'receipt' => $receipt, 'configuration' => $configuration, 'policy_version' => $policyVersion,
                    'accepted_at' => $receipt['accepted_at'], 'recorded_at' => $row->recorded_at];
            }
            $size = strlen(Wire::json($packet));
            if ($bytes + $size > 3 * 1048576) {
                KitchenFault::require($entries !== [], 'cloud_intake_record_too_large', 503);
                break;
            }
            $entries[] = $packet;
            $bytes += $size;
        }
        $next = $entries === [] ? $after : $entries[array_key_last($entries)]['cursor'];

        return response()->json(['data' => ['entries' => $entries, 'next_cursor' => $next,
            'has_more' => (int) $a->branch->sequence > $next]])->header('Cache-Control', 'no-store, private');
    }

    public function grants(Request $request)
    {
        $host = $request->input('kind') === 'host';
        $a = Access::from($request, $host ? 'host_grant' : 'kitchen.submit', $host);
        $scopes = $host ? ['kitchen.host'] : ['kitchen.submit', 'kitchen.approve', 'kitchen.handover'];
        if (! $host) {
            try {
                Access::staffAllowed($a->device, $a->staffId, 'kitchen.complete');
                $scopes = [...$scopes, 'kitchen.complete', 'kitchen.undo'];
            } catch (KitchenFault) {
            }
        }

        return response()->json(['data' => app(Grants::class)->issue($a->identity(), $scopes, $a->staffId)]);
    }

    /** Upload a bounded coordinator journal batch. Credentials are verified but never journaled. */
    public function sync(Request $request)
    {
        KitchenFault::require(strlen($request->getContent()) <= 1048576, 'kitchen_payload_too_large', 413);
        $host = Access::from($request, 'delivery', true);
        Validator::make($request->all(), ['events' => 'required|array|min:1|max:50', 'events.*' => 'array:device_id,grant,event,accepted_at', 'events.*.device_id' => 'required|integer|min:1', 'events.*.grant' => 'required|string|max:16384', 'events.*.event' => 'required|array', 'events.*.accepted_at' => 'nullable|date'])->validate();
        $results = DB::transaction(function () use ($request, $host): array {
            $results = [];
            foreach ($request->input('events') as $entry) {
                $d = Device::query()->whereKey($entry['device_id'])->where('company_id', $host->device->company_id)->where('branch_id', $host->device->branch_id)->where('status', 'active')->first();
                $b = DB::table('pos_kv2_devices')->where('device_id', $entry['device_id'])->where('company_id', $host->device->company_id)->where('branch_id', $host->device->branch_id)->where('enabled', true)->first();
                KitchenFault::require($d && $b && $b->assignment === $d->assignment_activated_at?->toIso8601String(), 'sync_identity_quarantined', 403);
                $event = $entry['event'];
                $action = $event['action'] ?? '';
                $scope = match ($action) {
                    'submit' => 'kitchen.submit','claim_delivery','delivery_result' => 'kitchen.host','item_done','done_all' => 'kitchen.complete','undo' => 'kitchen.undo','served','collected' => 'kitchen.handover','approve','reject','amend','cancel','link','reprint','resume_delivery' => 'kitchen.approve',default => throw new KitchenFault('action_unsupported', 422)
                };
                KitchenFault::require($d->isAttended() || ($d->device_type === 'kitchen_display' && in_array($scope, ['kitchen.complete', 'kitchen.undo'], true)), 'sync_role_forbidden', 403);
                $identity = new Access($d, $host->branch, $b);
                $claims = app(Grants::class)->verify($entry['grant'], $identity->identity(), $scope);
                if ($scope === 'kitchen.host') {
                    KitchenFault::require((int) $d->id === (int) $host->device->id && ($claims['staff_id'] ?? null) === null, 'executor_forbidden', 403);
                } else {
                    KitchenFault::require(is_int($claims['staff_id'] ?? null), 'staff_unverified', 403);
                    Access::staffAllowed($d, $claims['staff_id'], $scope);
                }

                // Reuse exactly the direct-request shape guard; no bearer/grant in the receipt hash or payload.
                $synthetic = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], Wire::json($event));
                $event = $this->input($synthetic);
                $accepted = isset($entry['accepted_at']) ? CarbonImmutable::parse($entry['accepted_at']) : null;
                if ($accepted) {
                    KitchenFault::require($accepted->getTimestampMs() >= $claims['iat'] * 1000 && $accepted->getTimestampMs() < $claims['exp'] * 1000 && $accepted->getTimestampMs() <= now()->getTimestampMs() + 1000, 'acceptance_time_invalid', 422);
                }
                // Authenticated assigned coordinator attests original acceptance;
                // server recorded_at remains the separate ingestion timestamp.
                $origin = new Access($d, $host->branch, $b, $claims['staff_id'] ?? null, $accepted);
                $results[] = $this->journal->apply($origin, $event, fn ($state) => $action === 'submit' ? $this->submissions->submit($origin, $state, $event) : (in_array($action, ['claim_delivery', 'delivery_result', 'reprint', 'resume_delivery'], true) ? $this->deliveries->apply($origin, $event) : $this->submissions->event($origin, $state, $event)));
            }

            return $results;
        }, 5);

        return response()->json(['data' => ['receipts' => $results, 'durable' => true]]);
    }

    private function cursor(Request $r): int
    {
        Validator::make($r->query(), ['after' => 'sometimes|integer|min:0'])->validate();

        return (int) $r->query('after', 0);
    }
}
