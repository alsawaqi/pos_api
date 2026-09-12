<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\AllocateQrRoundAcceptedSequenceAction;
use App\Actions\Qr\AppendQrPricedLinesAction;
use App\Actions\Qr\FreezeQrRoundLinesAction;
use App\Actions\Qr\LoadQrPricingInputAction;
use App\Actions\Qr\QrCatalogueException;
use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\Product;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Support\Money;
use App\Support\Pricing\Totals;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Staff submissions use the same priced, append-only ledger as QR rounds. */
final class AppendStaffRoundAction
{
    public function __construct(
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly OpenStaffTableSessionAction $opens,
        private readonly LoadQrPricingInputAction $pricing,
        private readonly FreezeQrRoundLinesAction $freeze,
        private readonly AppendQrPricedLinesAction $append,
        private readonly RefreshQrOrderTotalsAction $totals,
        private readonly AllocateQrRoundAcceptedSequenceAction $acceptedSequence,
        private readonly AppendTableSessionEventAction $journal,
        private readonly EnsureLegacyTableBillBaselineAction $baseline,
    ) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, array $payload, CarbonInterface $clientAt, CarbonInterface $receivedAt, ?string $uuid = null): array
    {
        return $this->resolver->locked($device, $payload, 'round', function ($device, $tables, $orders, $sessions, $seatings) use ($payload, $clientAt, $receivedAt, $uuid): array {
            $resolved = $this->resolver->resolve($seatings, $payload['seating_key'], $uuid);
            $row = $resolved['row'];
            $primary = $resolved['primary'];
            $created = false;
            if ($row === null) {
                // Owner-approved R4 clarification: unknown key on an occupied
                // table uses the ordinary open resolver, not a second seating.
                // A frozen bill must remain byte-unchanged, including aliases.
                $live = $seatings->first(fn (TableSession $seat): bool => (int) $seat->table_id === (int) $payload['table_id'] && $this->resolver->isLive($seat));
                $candidate = $live?->merged_into_id === null ? $live : $seatings->get((int) $live->merged_into_id);
                $bill = $candidate === null ? null : $orders->get((int) $candidate->order_id);
                if ($bill !== null && $bill->status !== Order::STATUS_OPEN) {
                    $terminal = in_array($bill->status, [Order::STATUS_PAID, Order::STATUS_VOID, Order::STATUS_REFUNDED, Order::STATUS_PENDING_VERIFICATION], true);

                    return $this->resolver->result($terminal ? 'bill_terminal' : 'bill_unpaid', $payload, null, $candidate, $bill, $terminal);
                }
                $opened = $this->opens->openLocked($device, $payload + ['opened_at' => $payload['submitted_at']], $clientAt, $receivedAt, $seatings);
                $row = $opened['row'];
                $primary = $opened['primary'];
                $created = $opened['outcome'] === 'opened';
                $resolved = $this->resolver->resolve($seatings, $payload['seating_key']);
            }
            $order = $primary === null ? null : $orders->get((int) $primary->order_id);
            if ($primary !== null) {
                $existing = QrOrderRound::query()->where('table_session_id', $primary->id)
                    ->where('client_request_id', $payload['client_request_id'])->first();
                if ($existing !== null) {
                    return $this->resolver->result('replayed', $payload, $row, $primary, $order, (bool) $existing->needs_review)
                        + $this->presentRound($existing);
                }
            }
            if (! $this->resolver->isLive($resolved['winner']) || ! $this->resolver->isLive($primary)
                || ($order !== null && in_array($order->status, [Order::STATUS_PAID, Order::STATUS_VOID, Order::STATUS_REFUNDED, Order::STATUS_PENDING_VERIFICATION], true))) {
                return $this->resolver->result('bill_terminal', $payload, $row, $primary, $order, true);
            }
            // Do not change a quoted/reserved bill. Reopening is the only path
            // that authorizes new money; no claim/provenance fields change here.
            if (($order !== null && $order->status !== Order::STATUS_OPEN) || $primary->status !== TableSession::STATUS_OPEN) {
                return $this->resolver->result('bill_unpaid', $payload, $row, $primary, $order);
            }

            if ($order !== null) {
                $this->baseline->handle($order);
            }

            $now = now();
            $requestedLines = array_map(static fn (array $line): array => $line + ['addon_ids' => [], 'notes' => null], array_values($payload['lines']));
            $at = DateTimeImmutable::createFromInterface($now);
            $classified = $this->pricing->classify((int) $device->company_id, (int) $device->branch_id, $requestedLines, $at);
            $held = $classified['held'];
            $loaded = null;
            $price = null;
            if ($classified['priceable'] !== []) {
                try {
                    $loaded = $this->pricing->handle((int) $device->company_id, (int) $device->branch_id, $classified['priceable'], $at);
                    $price = Totals::priceOrder($loaded->pricingInput);
                } catch (QrCatalogueException $exception) {
                    // A catalogue edit between classification and pricing holds
                    // the entire request; never keep a partially priced race.
                    $loaded = null;
                    $held = array_map(static fn (array $line, int $index): array => [
                        'line_index' => $index, 'product_id' => (int) $line['product_id'],
                        'addon_id' => null, 'reason' => $exception->codeName,
                    ], $requestedLines, array_keys($requestedLines));
                }
            }
            $merged = $row->status === TableSession::STATUS_MERGED && $row->close_reason === TableSession::CLOSE_MERGED;
            $needsReview = $merged || $held !== [];
            $reviewReasons = array_merge($merged ? ['merged'] : [], $held !== [] ? ['catalogue'] : []);
            // Existing pricing helpers read only company_id from this typed
            // context. It is never saved: staff rounds have NO QR credential.
            $pricingContext = new QrSession(['company_id' => $device->company_id]);
            $printedAt = isset($payload['printed_at']) ? Carbon::parse($payload['printed_at']) : null;
            $printRejected = $printedAt !== null && $printedAt->gt($receivedAt->copy()->addSeconds(300));
            if ($printRejected) {
                $printedAt = null;
            }
            if ($order === null) {
                $order = Order::query()->create([
                    'uuid' => isset($payload['order_uuid']) && ! Order::query()->where('uuid', $payload['order_uuid'])->exists()
                        ? $payload['order_uuid'] : (string) Str::uuid(),
                    'company_id' => $device->company_id,
                    'branch_id' => $device->branch_id,
                    'device_id' => $device->id,
                    'staff_id' => $payload['staff_id'] ?? null,
                    'table_id' => $primary->table_id,
                    'table_session_id' => $primary->id,
                    'qr_session_id' => null,
                    'client_request_id' => null,
                    'client_event_id' => null,
                    'order_type' => 'dine_in',
                    'source' => $device->device_type === 'handheld' ? 'handheld' : 'main_pos',
                    'status' => Order::STATUS_OPEN,
                    'subtotal' => Money::toOmr(0),
                    'discount_total' => Money::toOmr(0),
                    'comp_total' => Money::toOmr(0),
                    'tax_total' => Money::toOmr(0),
                    'grand_total' => Money::toOmr(0),
                    'opened_at' => Carbon::parse($payload['submitted_at']),
                    'temp_reference' => $primary->temp_reference,
                    'receipt_number' => null,
                ]);
                $primary->update(['order_id' => $order->id]);
                foreach ($seatings as $joined) {
                    if ((int) $joined->merged_into_id === (int) $primary->id && $this->resolver->isLive($joined)) {
                        $joined->update(['order_id' => $order->id]);
                        DB::table('pos_order_tables')->insertOrIgnore(['order_id' => $order->id, 'table_id' => $joined->table_id]);
                    }
                }
            }
            $round = QrOrderRound::query()->create([
                'qr_session_id' => null,
                'table_session_id' => $primary->id,
                'origin_table_session_id' => $merged ? $row->id : null,
                'order_id' => $order->id,
                'client_request_id' => $payload['client_request_id'],
                'round_no' => (int) QrOrderRound::query()->where('order_id', $order->id)->max('round_no') + 1,
                'status' => $needsReview ? QrOrderRound::STATUS_PENDING_CONFIRMATION : QrOrderRound::STATUS_ACCEPTED,
                'needs_review' => $needsReview,
                'kitchen_printed_at' => $printedAt,
                'priced_lines' => $this->storedLines((int) $device->company_id, $requestedLines, $held, $loaded === null ? [] : $this->freeze->handle($loaded, $price)),
                'confirm_payload' => $needsReview && $loaded !== null ? $this->append->buildPayload($pricingContext, $loaded, $price, $now) : null,
                'subtotal_baisas' => $price?->rawSubtotalBaisas ?? 0,
                'tax_baisas' => $price?->taxTotalBaisas ?? 0,
                'total_baisas' => $price?->grandTotalBaisas ?? 0,
                'submitted_at' => Carbon::parse($payload['submitted_at']),
                'resolved_at' => $needsReview ? null : $now,
                'resolved_by_device_id' => $needsReview ? null : $device->id,
                'accepted_seq' => null,
            ]);
            if (! $needsReview) {
                $itemIds = $this->append->handle($order, $pricingContext, $loaded, $price, $now);
                $lines = $round->priced_lines;
                $pricedIndex = 0;
                foreach ($lines as &$line) {
                    if (! isset($line['held_reason'])) {
                        $line['order_item_id'] = $itemIds[$pricedIndex++];
                    }
                }
                unset($line);
                $round->update(['priced_lines' => $lines]);
                $this->totals->handle($order);
                $round->update(['accepted_seq' => $this->acceptedSequence->next()]);
            }
            $data = $this->resolver->clockEvidence($clientAt, $receivedAt) + [
                'round_id' => (int) $round->id,
                'order_uuid' => $order->uuid,
                'origin_table_session_uuid' => $merged ? $row->uuid : null,
                'review_reasons' => $reviewReasons,
                'held_product_ids' => array_values(array_unique(array_column($held, 'product_id'))),
                'held_line_count' => count($held),
            ];
            if ($printRejected) {
                $data['print_evidence_rejected'] = true;
            }
            $this->journal->handle($primary, $needsReview ? 'round_pending' : 'round_appended', $data, (int) $device->id);
            if ($needsReview) {
                $this->journal->handle($primary, 'needs_review', $data, (int) $device->id);
            }

            return $this->resolver->result($merged ? 'merged' : ($held !== [] ? 'held' : ($created ? 'seating_created' : 'appended')), $payload, $row, $primary, $order, $needsReview)
                + $this->presentRound($round);
        });
    }

    /**
     * Preserve original line order and request data without reading client prices.
     *
     * @param  list<array<string, mixed>>  $requested
     * @param  list<array<string, mixed>>  $held
     * @param  list<array<string, mixed>>  $frozen
     * @return list<array<string, mixed>>
     */
    private function storedLines(int $companyId, array $requested, array $held, array $frozen): array
    {
        $heldByIndex = array_column($held, null, 'line_index');
        $names = Product::query()->where('company_id', $companyId)
            ->whereIn('id', array_column($held, 'product_id'))->pluck('name', 'id');
        $stored = [];
        $pricedIndex = 0;
        foreach ($requested as $index => $line) {
            if (! isset($heldByIndex[$index])) {
                $stored[] = ['line_index' => $index] + $frozen[$pricedIndex++];

                continue;
            }
            $heldLine = $heldByIndex[$index];
            $stored[] = [
                'line_index' => $index,
                'product_id' => (int) $line['product_id'],
                'product_name' => $names->get($line['product_id']),
                'qty' => $line['qty'],
                'notes' => $line['notes'],
                'addon_ids' => $line['addon_ids'],
                'requested' => true,
                'held_reason' => $heldLine['reason'],
                'addon_id' => $heldLine['addon_id'],
                'unit_price_baisas' => null,
                'line_total_baisas' => null,
            ];
        }

        return $stored;
    }

    /** @return array<string, mixed> */
    private function presentRound(QrOrderRound $round): array
    {
        $held = [];
        foreach ($round->priced_lines ?? [] as $index => $line) {
            if (isset($line['held_reason'])) {
                $held[] = [
                    'line_index' => (int) ($line['line_index'] ?? $index),
                    'product_id' => (int) $line['product_id'],
                    'addon_id' => $line['addon_id'] ?? null,
                    'reason' => $line['held_reason'],
                ];
            }
        }

        return [
            'round_id' => (int) $round->id,
            'round_no' => (int) $round->round_no,
            'round_status' => $round->status,
            'total_baisas' => (int) $round->total_baisas,
            'accepted_seq' => $round->accepted_seq,
            'print_pending' => (bool) $round->needs_review && $round->status === QrOrderRound::STATUS_ACCEPTED && $round->kitchen_printed_at === null,
            'review_reasons' => array_merge($round->origin_table_session_id !== null ? ['merged'] : [], $held !== [] ? ['catalogue'] : []),
            'held_lines' => $held,
        ];
    }
}
