<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use App\Rules\DistinctLineAddons;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** Branch-local identities; a historical key never resolves to a new party. */
final class ResolveStaffSeatingAction
{
    private const SNAPSHOT_CHANGED = 'table-session order appeared while acquiring credential locks';

    public function __construct(private readonly AppendTableSessionEventAction $journal) {}

    /** @return array<string, mixed> */
    public static function rules(string $operation): array
    {
        $common = [
            'seating_key' => ['required', 'uuid'],
            'table_id' => ['required', 'integer', 'min:1'],
            'queued_offline' => ['required', 'boolean'],
            'staff_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];

        return $common + match ($operation) {
            // The combine action validates its own explicit preview/PIN input.
            'combine', 'draft_recovery', 'staff_checkout' => [],
            'adjust' => TableAdjustmentIntent::rules(),
            'open' => [
                'opened_at' => ['required', 'date'],
                'joined_table_ids' => ['sometimes', 'array', 'max:100'],
                'joined_table_ids.*' => ['integer', 'min:1', 'distinct'],
                'order_uuid' => ['sometimes', 'nullable', 'uuid'],
            ],
            'round' => [
                'order_uuid' => ['sometimes', 'nullable', 'uuid'],
                'client_request_id' => ['required', 'string', 'max:64'],
                'submitted_at' => ['required', 'date'],
                'printed_at' => ['sometimes', 'nullable', 'date'],
                'lines' => ['required', 'array', 'min:1', 'max:100'],
                'lines.*.product_id' => ['required', 'integer', 'min:1'],
                'lines.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
                'lines.*.addon_ids' => ['sometimes', 'array', 'max:50', new DistinctLineAddons],
                'lines.*.addon_ids.*' => ['integer', 'min:1'],
                'lines.*.notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'lines.*.unit_price_baisas' => ['missing'],
                'lines.*.line_total_baisas' => ['missing'],
                'subtotal_baisas' => ['missing'],
                'total_baisas' => ['missing'],
                'grand_total_baisas' => ['missing'],
            ],
            'move' => [
                'from_table_id' => ['required', 'integer', 'min:1'],
                'to_table_id' => ['required', 'integer', 'min:1'],
                'moved_at' => ['required', 'date'],
            ],
            'join' => [
                'join_table_ids' => ['required', 'array', 'min:1', 'max:100'],
                'join_table_ids.*' => ['integer', 'min:1', 'distinct'],
                'joined_at' => ['required', 'date'],
            ],
            'cancel_bill' => [
                'client_request_id' => ['required', 'string', 'max:64'],
                'reason' => ['required', 'string', 'max:200'],
                'authorized_by' => ['required', 'string', 'max:100'],
                'cancelled_at' => ['required', 'date'],
                'lines' => ['present', 'array', 'max:100'],
                'lines.*' => ['array:client_request_id,product_id,addon_ids,notes,qty,prepared'],
                'lines.*.client_request_id' => ['required', 'string', 'max:64', 'distinct'],
                'lines.*.product_id' => ['required', 'integer', 'min:1'],
                'lines.*.addon_ids' => ['sometimes', 'array', 'max:50'],
                'lines.*.addon_ids.*' => ['integer', 'min:1'],
                'lines.*.notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'lines.*.qty' => ['required', 'integer', 'min:1'],
                'lines.*.prepared' => ['required', 'boolean'],
            ],
            'cancel_line' => [
                'client_request_id' => ['required', 'string', 'max:64'],
                'product_id' => ['required', 'integer', 'min:1'],
                'addon_ids' => ['sometimes', 'array', 'max:50'],
                'addon_ids.*' => ['integer', 'distinct'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'qty' => ['required', 'integer', 'min:1', 'max:999'],
                'prepared' => ['required', 'boolean'],
                'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
                'authorized_by' => ['sometimes', 'nullable', 'string', 'max:100'],
                'cancelled_at' => ['required', 'date'],
            ],
            'close' => [
                'closed_at' => ['required', 'date'],
                'reason' => ['required', 'in:staff_close'],
            ],
            default => throw new RuntimeException('Unknown staff seating operation.'),
        };
    }

    /**
     * Lock one branch's table graph before its orders/credentials/seatings.
     * This intentionally serializes staff seating mutations within a branch:
     * membership cannot move out of the discovered lock set while we wait.
     * Existing QR writers still serialize through their order/credential locks.
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(Device, Collection, Collection, Collection, Collection): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function locked(Device $device, array $payload, string $kind, Closure $operation): array
    {
        Validator::make($payload, self::rules($kind))->validate();

        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($device, $payload, $operation, $kind): array {
                    // PostgreSQL FK checks take KEY SHARE on these parent
                    // rows. NO KEY UPDATE still serializes staff graph edits
                    // but cannot invert a QR writer's order -> journal locks.
                    $graphLock = DB::connection()->getDriverName() === 'pgsql' ? 'for no key update' : true;
                    $lockedDevice = Device::query()->whereKey($device->id)->lock($graphLock)->first();
                    if ($lockedDevice === null || $lockedDevice->status !== 'active' || ! $lockedDevice->isAssigned()
                        || (int) $lockedDevice->company_id !== (int) $device->company_id
                        || (int) $lockedDevice->branch_id !== (int) $device->branch_id) {
                        throw new QrDineInException('device_unassigned', 409, 'The device is not active and assigned.');
                    }
                    if (! in_array($lockedDevice->device_type, ['fixed_pos', 'handheld'], true)) {
                        throw new QrDineInException('device_not_attended', 409, 'An attended device is required.');
                    }

                    $companyId = (int) $lockedDevice->company_id;
                    $branchId = (int) $lockedDevice->branch_id;
                    $tables = Table::query()->withTrashed()
                        ->where('company_id', $companyId)
                        ->whereIn('floor_id', DB::table('pos_floors')->select('id')
                            ->where('company_id', $companyId)->where('branch_id', $branchId))
                        ->orderBy('id')->lock($graphLock)->get()->keyBy('id');
                    $requested = [(int) $payload['table_id']];
                    foreach (['from_table_id', 'to_table_id'] as $field) {
                        if (isset($payload[$field])) {
                            $requested[] = (int) $payload[$field];
                        }
                    }
                    foreach (['joined_table_ids', 'join_table_ids'] as $field) {
                        $requested = array_merge($requested, $payload[$field] ?? []);
                    }
                    foreach (array_unique($requested) as $tableId) {
                        $table = $tables->get((int) $tableId);
                        // Recovery may only replay its immutable receipt for
                        // an archived table. A new recovery still validates
                        // the live table in its action before any writes.
                        if (($table === null || $table->trashed()) && $kind !== 'draft_recovery') {
                            throw new QrDineInException('table_not_found', 404, 'The table was not found in this branch.');
                        }
                    }
                    if (isset($payload['staff_id']) && ! DB::table('pos_staff')
                        ->where('id', $payload['staff_id'])->where('company_id', $companyId)->exists()) {
                        throw new QrDineInException('staff_not_found', 404, 'The staff member was not found.');
                    }

                    // Order -> credential -> seating -> journal is shared
                    // with QR settlement. Lock live bills plus this request's
                    // historical identity, never every paid bill in a branch.
                    $linkedOrders = TableSession::query()->select('order_id')
                        ->where('company_id', $companyId)->where('branch_id', $branchId)
                        ->where(function (Builder $query) use ($payload): void {
                            $query->whereIn('status', TableSession::LIVE_STATUSES)
                                ->orWhere('client_request_id', $payload['seating_key'])
                                ->orWhere('uuid', $payload['seating_key']);
                        });
                    $orderScope = Order::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                        ->where('order_type', 'dine_in')->whereNotNull('table_id')
                        ->where(function (Builder $query) use ($payload, $linkedOrders): void {
                            $query->whereIn('status', ListTableBoardAction::UNPAID_STATUSES)
                                ->orWhereIn('id', $linkedOrders)
                                ->orWhere('uuid', $payload['seating_key']);
                            if (isset($payload['order_uuid'])) {
                                $query->orWhere('uuid', $payload['order_uuid']);
                            }
                        });
                    $orders = (clone $orderScope)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $sessions = QrSession::query()->where('company_id', $companyId)->where('branch_id', $branchId)
                        ->whereNotNull('table_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                    // A first QR round may create its order while we wait for
                    // the credential. Restart BEFORE taking seating/sequence
                    // locks rather than adding a session -> order lock edge.
                    $orderIds = (clone $orderScope)->pluck('id');
                    if ($orderIds->diff($orders->keys())->isNotEmpty()) {
                        throw new RuntimeException(self::SNAPSHOT_CHANGED);
                    }
                    $scope = TableSession::query()->where('company_id', $companyId)->where('branch_id', $branchId);
                    $primary = (clone $scope)->whereNull('merged_into_id')->orderBy('id')->lockForUpdate()->get();
                    $other = (clone $scope)->whereNotNull('merged_into_id')->orderBy('id')->lockForUpdate()->get();
                    $seatings = $primary->concat($other)->keyBy('id');
                    $result = $operation($lockedDevice, $tables, $orders, $sessions, $seatings);
                    $events = $this->journal->flush();
                    $ids = array_map(static fn ($event): int => (int) $event->id, $events);
                    $result['event_id'] = $ids[0] ?? (in_array($kind, ['combine', 'draft_recovery'], true) ? ($result['event_id'] ?? null) : null);
                    if (($result['held_lines'] ?? []) !== [] && isset($result['round_id'])) {
                        // A new seating may journal opened first. Revision 3
                        // specifically makes a catalogue hold point at its round.
                        foreach ($events as $event) {
                            if ($event->event_type === 'round_pending'
                                && (int) ($event->payload['round_id'] ?? 0) === (int) $result['round_id']) {
                                $result['event_id'] = (int) $event->id;
                                break;
                            }
                        }
                    }
                    if (count($ids) > 1) {
                        $result['event_ids'] = $ids;
                    }

                    return $result;
                }, 5);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== self::SNAPSHOT_CHANGED || $attempt >= 4) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param  Collection<int, TableSession>  $seatings
     * @return array{row: ?TableSession, winner: ?TableSession, primary: ?TableSession}
     */
    public function resolve(Collection $seatings, string $key, ?string $uuid = null): array
    {
        $row = $uuid === null ? $seatings->firstWhere('client_request_id', $key) : $seatings->firstWhere('uuid', $uuid);
        if ($uuid !== null && $row === null) {
            throw new QrDineInException('table_session_not_found', 404, 'The seating was not found in this branch.');
        }
        $winner = $row;
        if ($row?->status === TableSession::STATUS_MERGED) {
            $winner = $seatings->get((int) $row->merged_into_id);
            if ($winner?->status === TableSession::STATUS_MERGED) {
                throw new RuntimeException('A seating alias must never point at another alias.');
            }
        }
        $primary = $winner;
        if ($winner !== null && $this->isLive($winner) && $winner->merged_into_id !== null) {
            $primary = $seatings->get((int) $winner->merged_into_id);
            if ($primary === null || $primary->merged_into_id !== null || $primary->status === TableSession::STATUS_MERGED) {
                throw new RuntimeException('A joined seating must point at its real primary.');
            }
        }

        return ['row' => $row, 'winner' => $winner, 'primary' => $primary];
    }

    public function isLive(?TableSession $seating): bool
    {
        return $seating !== null && in_array($seating->status, TableSession::LIVE_STATUSES, true);
    }

    public function isOffline(bool $queuedOffline, CarbonInterface $clientAt, CarbonInterface $receivedAt): bool
    {
        return $queuedOffline || $receivedAt->getTimestamp() - $clientAt->getTimestamp() > 300;
    }

    /** @return array<string, int> */
    public function clockEvidence(CarbonInterface $clientAt, CarbonInterface $receivedAt): array
    {
        $skew = $clientAt->getTimestamp() - $receivedAt->getTimestamp();

        return $skew > 300 ? ['clock_skew_seconds' => $skew] : [];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function result(string $outcome, array $payload, ?TableSession $row = null, ?TableSession $winner = null, ?Order $order = null, bool $needsReview = false): array
    {
        return [
            'outcome' => $outcome,
            'table_session_uuid' => $row?->uuid,
            'winner_table_session_uuid' => $winner !== null && $winner->id !== $row?->id ? $winner->uuid : null,
            'order_uuid' => $order?->uuid,
            'temp_reference' => $winner?->temp_reference ?? $row?->temp_reference,
            'needs_review' => $needsReview,
            'event_id' => null,
            'seating_key' => $payload['seating_key'],
            'table_id' => (int) $payload['table_id'],
        ];
    }
}
