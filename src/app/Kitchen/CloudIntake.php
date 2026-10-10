<?php

declare(strict_types=1);

namespace App\Kitchen;

use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ConfirmStaffRoundAction;
use App\Actions\Tablet\TabletOrderException;
use App\Actions\Tablet\TabletOrderStaffAction;
use App\Models\AddOn;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TabletOrder;
use App\Support\Catalogue\Allergens;
use App\Support\Catalogue\CookingTime;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Called inside the successful order transaction, never by a public client. */
final class CloudIntake
{
    public function capture(Order $order, string $source, array $priced, ?QrOrderRound $round = null, ?Access $actor = null): ?array
    {
        if (! Compatibility::ownsBranch((int) $order->company_id, (int) $order->branch_id)) {
            return null;
        }
        KitchenFault::require(DB::transactionLevel() > 0, 'cloud_intake_transaction_required', 500);
        KitchenFault::require(in_array($source, ['qr_web', 'customer_tablet'], true) || ($actor && in_array($source, ['main_pos', 'handheld'], true)), 'cloud_source_invalid', 500);
        if ($round && (($round->needs_review && $round->status !== 'accepted') || $round->status === 'rejected')) {
            return null; // Existing staff review owns invalid/merged/accounting-only work.
        }
        if ($round) {
            $priced = RoundAdmission::lines($round);
        }
        foreach ($priced as $line) {
            if (isset($line['held_reason']) || ($line['accounting_only'] ?? false)) {
                return null;
            }
        }
        $branch = DB::table('pos_kv2_branches')->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->lockForUpdate()->first();
        KitchenFault::require($branch && $branch->mode === 'active' && $branch->activation_state === 'idle', 'configuration_activation_pending');
        $device = Device::query()->whereKey($branch->coordinator_id)->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->where('status', 'active')->first();
        $binding = DB::table('pos_kv2_devices')->where('device_id', $device?->id)->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->where('enabled', true)->first();
        KitchenFault::require($device && $binding && $binding->assignment === $device->assignment_activated_at?->toIso8601String()
            && $binding->assignment === $branch->coordinator_assignment, 'coordinator_unavailable');
        $access = $actor ?? new Access($device, $branch, $binding);
        KitchenFault::require((int) $access->device->company_id === (int) $order->company_id &&
            (int) $access->device->branch_id === (int) $order->branch_id && (int) $access->branch->epoch === (int) $branch->epoch, 'cloud_identity_mismatch', 403);
        $key = $round ? 'round:'.$round->id : 'quick:'.$order->uuid;
        $existing = DB::table('pos_kv2_submissions')->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->where('source_key', $key)->first();
        if ($existing) {
            return app(Submissions::class)->receipt($existing);
        }
        $name = 'cloud:'.$order->company_id.':'.$order->branch_id.':'.$key;
        $intent = ['protocol_version' => 1, 'event_id' => Ids::stable($name.':event'), 'epoch' => (int) $branch->epoch,
            'occurred_at' => now()->toIso8601String(), 'action' => 'submit', 'submission_uuid' => Ids::stable($name),
            'order_uuid' => $order->uuid, 'round_uuid' => Ids::stable($name.':round'), 'revision' => 1,
            'policy_version' => (int) $branch->applied_version, 'source' => $source,
            'domain_event_uuid' => Ids::stable($name.':domain'), 'domain_state' => 'submitted',
            'context' => ['reference' => $order->receipt_number ?? $order->temp_reference ?? $order->uuid,
                'table' => $order->table_id ? DB::table('pos_tables')->where('id', $order->table_id)->value('label') : null,
                'order_type' => $order->order_type, 'notes' => $order->note],
            'lines' => $this->lines((int) $order->company_id, $name, $priced)];
        if ($round) {
            $intent['round_id'] = (int) $round->id;
        }
        $configuration = app(Submissions::class)->config($access, (int) $branch->applied_version);
        if ($configuration['policies'][in_array($source, ['main_pos', 'handheld'], true) ? 'staff' : $source] === 'immediate') {
            $this->confirm($access, $intent, automatic: true);
        }

        return app(Journal::class)->apply($access, $intent, fn ($state) => app(Submissions::class)->submit($access, $state, $intent, domainResolved: true));
    }

    /** Direct online staff rounds have no local financial outbox to replay. */
    public function directRound(Request $request, \Closure $operation): array
    {
        $device = $request->user();
        if (! Compatibility::ownsBranch((int) $device->company_id, (int) $device->branch_id)) {
            return $operation();
        }
        $access = Access::from($request, 'kitchen.submit');

        return DB::transaction(function () use ($operation, $access): array {
            $result = $operation();
            $roundId = $result['round_id'] ?? $result['addition']['id'] ?? null;
            if ($roundId !== null) {
                $round = QrOrderRound::query()->whereKey($roundId)->firstOrFail();
                $order = Order::query()->whereKey($round->order_id)->where('company_id', $access->device->company_id)->where('branch_id', $access->device->branch_id)->firstOrFail();
                $this->capture($order, $access->device->device_type === 'handheld' ? 'handheld' : 'main_pos', $round->priced_lines ?? [], $round, $access);
            }

            return $result;
        }, 5);
    }

    /** Existing confirmed-order buttons and the shared inbox converge once. */
    public function review(Request $request, \Closure $operation, string $action,
        ?int $roundId = null, ?string $tabletUuid = null): array
    {
        $device = $request->user();
        if (! Compatibility::ownsBranch((int) $device->company_id, (int) $device->branch_id)) {
            return $operation();
        }
        $access = Access::from($request, 'kitchen.approve');

        return DB::transaction(function () use ($operation, $access, $action, $roundId, $tabletUuid): array {
            $result = $operation();
            $query = DB::table('pos_kv2_submissions')->where('company_id', $access->device->company_id)->where('branch_id', $access->device->branch_id);
            if ($tabletUuid !== null) {
                $tablet = TabletOrder::query()->where('company_id', $access->device->company_id)->where('branch_id', $access->device->branch_id)->where('uuid', $tabletUuid)->firstOrFail();
                KitchenFault::require($action !== 'approve' || $tablet->sent_to_kitchen_at !== null, 'domain_hold');
                $query->where('order_id', $tablet->order_id)->where('source', 'customer_tablet');
                if ($tablet->isDineIn()) {
                    $query->where('round_id', $tablet->round_id);
                }
            } else {
                $round = QrOrderRound::query()->whereKey($roundId)->firstOrFail();
                KitchenFault::require($round->status === ($action === 'approve' ? 'accepted' : 'rejected'), 'domain_hold');
                $query->where('round_id', $roundId);
            }
            if ($action === 'reject') {
                $rejectedRoundId = $roundId ?? ($tablet->round_id ?? null);
                if ($rejectedRoundId !== null && (clone $query)->where('link_state', 'review_required')->exists()) {
                    $rejected = QrOrderRound::query()->whereKey($rejectedRoundId)->firstOrFail();
                    $order = Order::query()->whereKey($rejected->order_id)->where('company_id', $access->device->company_id)->where('branch_id', $access->device->branch_id)->firstOrFail();
                    DomainCancellation::reconcile($order, 'reject-round:'.$rejectedRoundId, whole: true, reviewedRound: (int) $rejectedRoundId);
                }
            }
            if ($action === 'approve') {
                $reviewedRoundId = $roundId ?? ($tablet->round_id ?? null);
                if ($reviewedRoundId !== null && (clone $query)->where('link_state', 'review_required')->exists()) {
                    $reviewed = QrOrderRound::query()->whereKey($reviewedRoundId)->firstOrFail();
                    RoundAdmission::lines($reviewed);
                    $order = Order::query()->whereKey($reviewed->order_id)->where('company_id', $access->device->company_id)->where('branch_id', $access->device->branch_id)->firstOrFail();
                    DomainCancellation::reconcile($order, 'review-round:'.$reviewedRoundId, reviewedRound: (int) $reviewedRoundId);
                }
                KitchenFault::require(! (clone $query)->whereIn('state', ['rejected', 'cancelled'])->exists(), 'kitchen_order_rejected');
                // A held round had no eligible kitchen submission. Its audited
                // review now supplies only the accepted, non-accounting subset.
                if (! (clone $query)->exists()) {
                    $reviewedRoundId = $roundId ?? ($tablet->round_id ?? null);
                    if ($reviewedRoundId !== null) {
                        $reviewed = QrOrderRound::query()->whereKey($reviewedRoundId)->firstOrFail();
                        $order = Order::query()->whereKey($reviewed->order_id)->where('company_id', $access->device->company_id)->where('branch_id', $access->device->branch_id)->firstOrFail();
                        $this->capture($order, RoundAdmission::source($order, $reviewed), RoundAdmission::lines($reviewed), $reviewed, $access);
                    }
                }
            }
            foreach ($query->where('state', 'waiting_approval')->lockForUpdate()->get() as $submission) {
                $event = ['protocol_version' => 1, 'event_id' => Ids::stable('domain-review:'.$submission->uuid.':'.$submission->revision.':'.$action),
                    'epoch' => (int) $access->branch->epoch, 'occurred_at' => now()->toIso8601String(), 'action' => $action,
                    'submission_uuid' => $submission->uuid, 'expected_revision' => (int) $submission->revision];
                app(Journal::class)->apply($access, $event, fn ($state) => app(Submissions::class)->event($access, $state, $event, domainResolved: true));
            }

            return $result;
        }, 5);
    }

    /** Preserve Take/Take-over for manual approval; automatic policy never approves loyalty. */
    public function confirm(Access $a, array $intent, bool $automatic = false): void
    {
        try {
            $this->confirmDomain($a, $intent, $automatic);
        } catch (TabletOrderException $error) {
            throw new KitchenFault($error->codeName, $error->httpStatus, $error->data ?? []);
        } catch (QrDineInException $error) {
            throw new KitchenFault($error->codeName, $error->httpStatus, $error->details);
        }
    }

    private function confirmDomain(Access $a, array $intent, bool $automatic): void
    {
        if (! in_array($intent['source'], ['qr_web', 'customer_tablet'], true)) {
            return;
        }
        $order = Order::query()->where('company_id', $a->device->company_id)->where('branch_id', $a->device->branch_id)->where('uuid', $intent['order_uuid'])->firstOrFail();
        $tablet = $intent['source'] === 'customer_tablet' ? TabletOrder::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->where('order_id', $order->id)
            ->when(isset($intent['round_id']), fn ($q) => $q->where('round_id', $intent['round_id']))->first() : null;
        if ($tablet && ! $automatic) {
            KitchenFault::require($a->staffId !== null, 'staff_unverified', 403);
            app(TabletOrderStaffAction::class)->send($a->device, $a->staffId, $tablet->uuid);

            return;
        }
        if (isset($intent['round_id'])) {
            $round = QrOrderRound::query()->whereKey($intent['round_id'])->where('order_id', $order->id)->firstOrFail();
            if ($round->status === QrOrderRound::STATUS_PENDING_CONFIRMATION) {
                KitchenFault::require(! $round->needs_review && $round->table_session_id, 'domain_hold');
                $seat = DB::table('pos_table_sessions')->where('id', $round->table_session_id)->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->first();
                KitchenFault::require($seat !== null, 'domain_hold');
                if ($round->qr_session_id !== null) {
                    app(ConfirmDineInQrRoundAction::class)->handle($a->device, (int) $round->id);
                } else {
                    app(ConfirmStaffRoundAction::class)->handle($a->device, $seat->uuid, (int) $round->id);
                }
                KitchenFault::require($round->fresh()->status === 'accepted', 'domain_hold');
            }
        }
        if ($tablet && $automatic && $tablet->sent_to_kitchen_at === null) {
            // No invented staff identity and no changes to Taken-by or loyalty.
            $tablet->update(['sent_to_kitchen_at' => now(), 'sent_by_device_id' => $a->device->id]);
        }
    }

    /** Stable identities include the line position, so identical products remain distinct. */
    private function lines(int $company, string $name, array $priced): array
    {
        $lines = [];
        $allergens = Allergens::load($company);
        foreach ($priced as $index => $line) {
            $id = Ids::stable($name.':line:'.$index);
            $lines[] = $this->line($company, $id, $line, $allergens);
            foreach ($line['components'] ?? [] as $position => $component) {
                $child = $this->line($company, Ids::stable($name.':line:'.$index.':component:'.$position), [
                    ...$component, 'qty' => (string) BigDecimal::of((string) $line['qty'])->multipliedBy((string) $component['qty']),
                ], $allergens);
                $lines[] = [...$child, 'parent_line_uuid' => $id, 'meal' => $line['display_name'] ?? $line['product_name'] ?? null];
            }
        }

        return $lines;
    }

    private function line(int $company, string $id, array $line, Allergens $allergens): array
    {
        $product = DB::table('pos_products')->where('company_id', $company)->where('id', $line['product_id'])->first();
        KitchenFault::require($product !== null, 'catalogue_hold');
        $contains = $allergens->product((int) $product->id);
        $known = $contains['contains'];
        $traces = $contains['may_contain'];
        $modifiers = [];
        $removals = [];
        foreach ($line['addons'] ?? [] as $addon) {
            $addonId = (int) ($addon['add_on_id'] ?? $addon['id'] ?? 0);
            $row = AddOn::query()->where('company_id', $company)->whereKey($addonId)->first();
            $label = $addon['name'] ?? $row?->name;
            KitchenFault::require(is_string($label) && $label !== '', 'modifier_missing', 422);
            $kind = $row ? DB::table('pos_addon_groups')->where('id', $row->add_on_group_id)->value('kind') : null;
            if ($kind === 'remove') {
                $removals[] = $label;
            } else {
                $modifiers[] = $label;
            }
            $known = [...$known, ...$allergens->addon($addonId)];
            $traces = [...$traces, ...$allergens->addonMayContain($addonId)];
        }

        $minutes = $line['attributes']['cooking_minutes'] ?? $line['cooking_minutes'] ?? null;
        if (isset($line['order_item_id'])) {
            $item = DB::table('pos_order_items')->where('id', $line['order_item_id'])->where('company_id', $company)->first();
            $minutes = $item?->cooking_minutes === null ? null : (int) $item->cooking_minutes;
        } elseif (! array_key_exists('cooking_minutes', $line) && ! array_key_exists('cooking_minutes', $line['attributes'] ?? [])) {
            $minutes = CookingTime::of($product);
        }

        return ['line_uuid' => $id, 'product_id' => (int) $product->id,
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'quantity' => (string) BigDecimal::of((string) $line['qty'])->toScale(6),
            'name' => $line['product_name'] ?? $line['name'] ?? $product->name,
            'name_ar' => $line['product_name_ar'] ?? $line['name_ar'] ?? $product->name_ar ?? null,
            'cooking_minutes' => $minutes,
            'notes' => $line['notes'] ?? null, 'modifiers' => $modifiers,
            'removals' => $removals, 'allergens' => array_values(array_unique([...$known, ...array_map(fn ($v) => 'May contain: '.$v, $traces)]))];
    }
}
