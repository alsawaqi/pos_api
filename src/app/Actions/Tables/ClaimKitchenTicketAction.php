<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** A claim is durable until an explicit failed result, never a timed lease. */
final class ClaimKitchenTicketAction
{
    private const SNAPSHOT_CHANGED = 'Kitchen round parents changed while acquiring locks';

    public function __construct(private readonly AppendTableSessionEventAction $journal) {}

    /** @param array{ticket_key: string} $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, array $payload): array
    {
        return $this->locked($device, $payload['ticket_key'], function (
            Device $holder, Order $order, QrOrderRound $round, ?TableSession $seating, ?KitchenTicket $ticket,
        ): array {
            $held = collect($round->priced_lines ?? [])->contains(
                static fn (array $line): bool => isset($line['held_reason']),
            );
            $evidence = $round->kitchen_printed_at === null ? null : Carbon::parse($round->kitchen_printed_at);
            $mergedReprint = $round->status === QrOrderRound::STATUS_PENDING_CONFIRMATION
                && ! $held && $round->origin_table_session_id !== null
                && $evidence !== null && $evidence->lte(now()->addSeconds(300));
            if ($round->status !== QrOrderRound::STATUS_ACCEPTED && ! $mergedReprint) {
                throw new QrDineInException('kitchen_round_not_printable', 409, 'This round is not eligible for printing.');
            }

            if ($ticket !== null && $ticket->print_result !== 'failed') {
                if ((int) $ticket->claimed_by_device_id !== (int) $holder->id) {
                    throw new QrDineInException('kitchen_ticket_claimed', 409, 'Another device holds this kitchen ticket.');
                }

                return $this->present($ticket, $order, $round, true);
            }

            $values = [
                'company_id' => $holder->company_id, 'branch_id' => $holder->branch_id,
                'ticket_key' => 'round:'.$round->id, 'round_id' => $round->id, 'order_id' => $order->id,
                'claimed_by_device_id' => $holder->id, 'claimed_at' => now(),
                'print_result' => null, 'printed_at' => null,
            ];
            if ($ticket === null) {
                $ticket = KitchenTicket::query()->create($values);
            } else {
                $ticket->update($values);
            }
            if ($seating !== null) {
                $this->journal->handle($seating, 'print_claimed', [
                    'ticket_key' => $ticket->ticket_key, 'round_id' => (int) $round->id,
                    'order_uuid' => (string) $order->uuid,
                ], (int) $holder->id);
            }

            return $this->present($ticket, $order, $round, false);
        });
    }

    /**
     * Lock device -> branch tables -> order -> credential -> seating -> round
     * -> ticket. Paid/legacy QR rounds may print without creating a seating.
     *
     * @param  Closure(Device, Order, QrOrderRound, ?TableSession, ?KitchenTicket): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function locked(Device $device, string $ticketKey, Closure $operation): array
    {
        if (! preg_match('/\Around:([1-9][0-9]*)\z/', $ticketKey, $matches)
            || strlen($ticketKey) > 96) {
            throw new QrDineInException('validation_failed', 422, 'The kitchen ticket key is invalid.');
        }
        $roundId = (int) $matches[1];

        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($device, $ticketKey, $roundId, $operation): array {
                    $holder = Device::query()->whereKey($device->id)->lockForUpdate()->first();
                    if ($holder === null || $holder->status !== 'active' || ! $holder->isAssigned()
                        || (int) $holder->company_id !== (int) $device->company_id
                        || (int) $holder->branch_id !== (int) $device->branch_id) {
                        throw new QrDineInException('device_unassigned', 409, 'An active assigned device is required.');
                    }
                    if (! in_array($holder->device_type, ['fixed_pos', 'handheld'], true)) {
                        throw new QrDineInException('device_not_attended', 409, 'An attended device is required.');
                    }
                    Table::query()->withTrashed()->where('company_id', $holder->company_id)
                        ->whereIn('floor_id', DB::table('pos_floors')->select('id')
                            ->where('company_id', $holder->company_id)->where('branch_id', $holder->branch_id))
                        ->orderBy('id')->lockForUpdate()->get();
                    $snapshot = QrOrderRound::query()->whereKey($roundId)
                        ->whereHas('order', fn ($query) => $query->where('company_id', $holder->company_id)
                            ->where('branch_id', $holder->branch_id)->where('order_type', 'dine_in')
                            ->whereIn('source', ['main_pos', 'handheld', Order::SOURCE_QR_WEB]))->first();
                    if ($snapshot === null) {
                        throw $this->notFound();
                    }
                    $order = Order::query()->whereKey($snapshot->order_id)
                        ->where('company_id', $holder->company_id)->where('branch_id', $holder->branch_id)
                        ->where('order_type', 'dine_in')
                        ->whereIn('source', ['main_pos', 'handheld', Order::SOURCE_QR_WEB])->lockForUpdate()->first();
                    if ($order === null) {
                        throw $this->notFound();
                    }
                    $session = $snapshot->qr_session_id === null ? null : QrSession::query()
                        ->whereKey($snapshot->qr_session_id)->where('company_id', $holder->company_id)
                        ->where('branch_id', $holder->branch_id)->lockForUpdate()->first();
                    // The bill proves dine-in even when a hard-deleted table
                    // left the legacy credential's nullable table_id empty.
                    if ($snapshot->qr_session_id !== null && $session === null) {
                        throw $this->notFound();
                    }
                    $seating = $snapshot->table_session_id === null ? null : TableSession::query()
                        ->whereKey($snapshot->table_session_id)->where('company_id', $holder->company_id)
                        ->where('branch_id', $holder->branch_id)->lockForUpdate()->first();
                    if (($snapshot->table_session_id !== null && ($seating === null
                        || (int) $seating->order_id !== (int) $order->id))
                        || ($seating === null && $session === null)) {
                        throw $this->notFound();
                    }
                    $round = QrOrderRound::query()->whereKey($roundId)->lockForUpdate()->first();
                    if ($round === null) {
                        throw $this->notFound();
                    }
                    foreach (['order_id', 'qr_session_id', 'table_session_id'] as $field) {
                        if ($round->getRawOriginal($field) !== $snapshot->getRawOriginal($field)) {
                            throw new RuntimeException(self::SNAPSHOT_CHANGED);
                        }
                    }
                    $ticket = KitchenTicket::query()->where('company_id', $holder->company_id)
                        ->where('branch_id', $holder->branch_id)->where('ticket_key', $ticketKey)
                        ->lockForUpdate()->first();
                    if ($ticket !== null && ((int) $ticket->round_id !== (int) $round->id
                        || (int) $ticket->order_id !== (int) $order->id)) {
                        throw $this->notFound();
                    }
                    $result = $operation($holder, $order, $round, $seating, $ticket);
                    $events = $this->journal->flush();
                    $ids = array_map(static fn ($event): int => (int) $event->id, $events);
                    $result['event_id'] = $ids[0] ?? null;
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

    /** @return array<string, mixed> */
    public function present(KitchenTicket $ticket, Order $order, QrOrderRound $round, bool $replayed): array
    {
        return [
            'ticket_key' => (string) $ticket->ticket_key, 'round_id' => (int) $round->id,
            'order_uuid' => (string) $order->uuid, 'claimed_by_device_id' => (int) $ticket->claimed_by_device_id,
            'claimed_at' => $ticket->claimed_at->toIso8601String(), 'print_result' => $ticket->print_result,
            'printed_at' => $ticket->printed_at?->toIso8601String(), 'replayed' => $replayed,
            'print_pending' => (bool) $round->needs_review
                && $round->status === QrOrderRound::STATUS_ACCEPTED && $round->kitchen_printed_at === null,
            'priced_lines' => array_values(array_filter($round->priced_lines ?? [],
                static fn (array $line): bool => ! isset($line['held_reason']))),
            'event_id' => null,
        ];
    }

    private function notFound(): QrDineInException
    {
        return new QrDineInException('kitchen_round_not_found', 404, 'The kitchen round was not found in this branch.');
    }
}
