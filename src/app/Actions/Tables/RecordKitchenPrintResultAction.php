<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/** Only the current claimant can record actual printer evidence. */
final class RecordKitchenPrintResultAction
{
    public function __construct(
        private readonly ClaimKitchenTicketAction $claims,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /** @param array{ticket_key: string, print_result: string, printed_at?: string|null} $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, array $payload): array
    {
        Validator::make($payload, [
            'ticket_key' => ['required', 'string', 'max:96', 'regex:/\Around:[1-9][0-9]*\z/'],
            'print_result' => ['required', 'in:printed,failed'],
            'printed_at' => ['required_if:print_result,printed', 'nullable', 'date'],
        ])->validate();
        $printedAt = $payload['print_result'] === 'printed' ? Carbon::parse($payload['printed_at']) : null;
        if ($printedAt !== null && $printedAt->gt(now()->addSeconds(300))) {
            throw new QrDineInException('validation_failed', 422, 'The printed timestamp is too far in the future.');
        }

        return $this->claims->locked($device, $payload['ticket_key'], function (
            Device $holder, Order $order, QrOrderRound $round, ?TableSession $seating, ?KitchenTicket $ticket,
        ) use ($payload, $printedAt): array {
            if ($ticket === null) {
                throw new QrDineInException('kitchen_ticket_not_found', 404, 'Claim this kitchen ticket before recording a result.');
            }
            if ((int) $ticket->claimed_by_device_id !== (int) $holder->id) {
                throw new QrDineInException('kitchen_ticket_claimed', 409, 'Another device holds this kitchen ticket.');
            }
            if ($ticket->print_result === 'printed' || $ticket->print_result === $payload['print_result']) {
                return $this->claims->present($ticket, $order, $round, true);
            }
            $ticket->update(['print_result' => $payload['print_result'], 'printed_at' => $printedAt]);
            if ($printedAt !== null) {
                $round->update(['kitchen_printed_at' => $printedAt]);
            }
            if ($seating !== null) {
                $this->journal->handle($seating, 'print_result', [
                    'ticket_key' => $ticket->ticket_key, 'round_id' => (int) $round->id,
                    'order_uuid' => (string) $order->uuid, 'print_result' => $payload['print_result'],
                    'printed_at' => $printedAt?->toIso8601String(),
                ], (int) $holder->id);
            }

            return $this->claims->present($ticket, $order, $round, false);
        });
    }
}
