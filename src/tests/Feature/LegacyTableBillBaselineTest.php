<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\AppendQrPricedLinesAction;
use App\Actions\Qr\ClaimQrChargeAction;
use App\Actions\Qr\ClaimQrSettlementAction;
use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Actions\Qr\FinishDineInQrOrderAction;
use App\Actions\Qr\FreezeQrRoundLinesAction;
use App\Actions\Qr\LoadQrPricingInputAction;
use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\CancelStaffLineAction;
use App\Actions\Tables\ConfirmStaffRoundAction;
use App\Actions\Tables\EnsureLegacyTableBillBaselineAction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\SyncEvent;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use App\Support\Pricing\Totals;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class LegacyTableBillBaselineTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-12 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_first_staff_append_counts_original_items_once_and_retries_never_duplicate_them(): void
    {
        [$device, $seat, $product, $event, $order] = $this->legacyFixture();
        $original = $order->items()->sole();
        $itemBefore = $original->getRawOriginal();
        $ackBefore = $event->getRawOriginal();
        $identity = $this->billIdentity($order);
        $payload = $this->staffPayload($seat, (int) $product->id, 3, 'staff-first');
        $ack = app(AppendStaffRoundAction::class)->handle($device, $payload, now(), now());

        $this->assertSame('appended', $ack['outcome']);
        $this->assertSame('5.000', $order->fresh()->grand_total);
        $this->assertSame(5.0, (float) $order->items()->sum('qty'));
        $this->assertSame($itemBefore, $original->fresh()->getRawOriginal());
        $this->assertSame($ackBefore, $event->fresh()->getRawOriginal());
        $this->assertSame($identity, $this->billIdentity($order->fresh()));
        $baseline = $this->baseline($order);
        $this->assertBaseline($baseline, $seat, $order, (int) $original->id);
        $this->assertSame(2, (int) QrOrderRound::findOrFail($ack['round_id'])->round_no);
        $this->assertNotNull(QrOrderRound::findOrFail($ack['round_id'])->accepted_seq);
        $this->assertDatabaseCount('pos_order_items', 2);

        $beforeRetry = $this->rows();
        $retry = app(AppendStaffRoundAction::class)->handle($device, $payload, now(), now());
        $this->assertSame('replayed', $retry['outcome']);
        $this->assertSame($ack['round_id'], $retry['round_id']);
        $this->assertSame($beforeRetry, $this->rows());
        $this->adopt($order);
        $this->assertSame($beforeRetry, $this->rows());

        app(AppendStaffRoundAction::class)->handle($device, $this->staffPayload($seat, (int) $product->id, 1, 'staff-next'), now(), now());
        $this->assertSame('6.000', $order->fresh()->grand_total);
        $this->assertDatabaseCount('pos_order_items', 3);
        $this->assertSame(1, QrOrderRound::where('client_request_id', 'legacy-baseline:'.$order->uuid)->count());
        $this->assertSame(1, TableSessionEvent::where('payload->action', 'legacy_bill_baselined')->count());
    }

    public function test_frozen_original_tax_is_retained_to_the_baisa_when_a_zero_tax_round_is_appended(): void
    {
        [$device, $seat, $product, $event, $order] = $this->legacyFixture();
        // A historical held bill can retain a tax amount that differs from
        // today's catalogue policy. Only its approved frozen amounts count.
        $payload = $event->payload_json;
        $payload['order']['tax_total_baisas'] = 100;
        $payload['order']['grand_total_baisas'] = 2100;
        $event->update(['payload_json' => $payload]);
        $order->update(['tax_total' => '0.100', 'grand_total' => '2.100']);
        $item = $order->items()->sole();
        $itemBefore = $item->getRawOriginal();
        $anchorBefore = $event->getRawOriginal();

        $this->adopt($order);
        $baseline = $this->baseline($order);
        $this->assertSame(2000, $baseline->subtotal_baisas);
        $this->assertSame(100, $baseline->tax_baisas);
        $this->assertSame(2100, $baseline->total_baisas);
        $this->assertSame((int) $item->id, $baseline->priced_lines[0]['order_item_id']);
        app(AppendStaffRoundAction::class)->handle($device,
            $this->staffPayload($seat, (int) $product->id, 3, 'zero-tax-followup'), now(), now());

        $this->assertSame('5.000', $order->fresh()->subtotal);
        $this->assertSame('0.100', $order->fresh()->tax_total);
        $this->assertSame('5.100', $order->fresh()->grand_total);
        $this->assertSame($itemBefore, $item->fresh()->getRawOriginal());
        $this->assertSame($anchorBefore, $event->fresh()->getRawOriginal());
    }

    public function test_accounting_adoption_preserves_every_original_row_and_uses_frozen_name_and_price(): void
    {
        [, $seat, $product, , $order] = $this->legacyFixture();
        $item = $order->items()->sole();
        $product->update(['name' => 'Renamed coffee', 'base_price' => '99.000', 'status' => 'inactive']);
        $before = $this->rows();

        $this->assertNotNull($this->adopt($order));

        $this->assertSame($this->withoutAccountingRows($before), $this->withoutAccountingRows($this->rows()));
        $baseline = $this->baseline($order);
        $this->assertBaseline($baseline, $seat, $order, (int) $item->id);
        $this->assertSame('Seating coffee', $baseline->priced_lines[0]['product_name']);
        $this->assertSame(1000, $baseline->priced_lines[0]['unit_price_baisas']);
        $journal = TableSessionEvent::where('payload->action', 'legacy_bill_baselined')->sole();
        $this->assertSame('attached', $journal->event_type);
        $this->assertSame($order->uuid, $journal->payload['order_uuid']);
        $this->assertSame([(int) $item->id], $journal->payload['order_item_ids']);
        $this->assertFalse($journal->payload['kitchen_submission']);
        $this->assertArrayNotHasKey('priced_lines', $journal->payload);
        $this->assertArrayNotHasKey('confirm_payload', $journal->payload);
    }

    public function test_observed_round_only_header_is_repaired_without_rewriting_either_set_of_items(): void
    {
        [$device, $seat, $product, , $order] = $this->legacyFixture();
        $legacyItem = $order->items()->sole();
        // Reproduce the pre-fix persisted state explicitly: the old writer
        // appended three items and refreshed the header from that round only.
        [$laterItem, $laterRound] = $this->historicalRound($seat, $order, $legacyItem);
        $order->update(['subtotal' => '3.000', 'grand_total' => '3.000']);
        $itemsBefore = $this->rows()['pos_order_items'];
        $roundBefore = $laterRound->fresh()->getRawOriginal();
        $identity = $this->billIdentity($order);

        $this->adopt($order);

        $this->assertSame('5.000', $order->fresh()->subtotal);
        $this->assertSame('5.000', $order->fresh()->grand_total);
        $this->assertSame($itemsBefore, $this->rows()['pos_order_items']);
        $this->assertSame($roundBefore, $laterRound->fresh()->getRawOriginal());
        $this->assertSame($identity, $this->billIdentity($order->fresh()));
        $this->assertBaseline($this->baseline($order), $seat, $order, (int) $legacyItem->id);
        $this->assertSame(2, (int) $this->baseline($order)->round_no);
        $journal = TableSessionEvent::where('payload->action', 'legacy_bill_baselined')->sole();
        $this->assertSame([3000, 0, 3000], $journal->payload['previous_totals_baisas']);
        $this->assertSame([5000, 0, 5000], $journal->payload['corrected_totals_baisas']);
        $beforeRetry = $this->rows();
        $this->adopt($order);
        $this->assertSame($beforeRetry, $this->rows());
        app(AppendStaffRoundAction::class)->handle($device, $this->staffPayload($seat, (int) $product->id, 1, 'after-repair'), now(), now());
        $this->assertSame('6.000', $order->fresh()->grand_total);
        $this->assertSame('3.000', $laterItem->fresh()->qty);
    }

    public static function unsafeEvidence(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'missing_anchor', 'missing_event', 'unprocessed_event', 'missing_processed_time', 'wrong_event_type',
            'foreign_anchor', 'wrong_ack_uuid', 'wrong_ack_order', 'wrong_ack_status', 'wrong_payload_uuid',
            'wrong_payload_qty', 'missing_payload_lines', 'fractional_qty', 'changed_price', 'changed_notes',
            'extra_item', 'discount_header', 'comp_header', 'discount_payload', 'comp_payload',
            'line_discount', 'joined_payload', 'joined_seating', 'claim', 'claim_amount', 'declined_claim',
            'transferred_to', 'transferred_from', 'transferred_at', 'wrong_header', 'billing', 'void_item',
            'reserved_request_collision', 'pending_item_owner', 'rejected_item_owner', 'duplicate_item_owner',
            'malformed_priced_lines', 'noninteger_item_id', 'nested_item_id', 'missing_order_seating_link',
            'existing_round_addon_mismatch',
        ]);
    }

    #[DataProvider('unsafeEvidence')]
    public function test_unverifiable_or_unsupported_baselines_refuse_with_every_row_unchanged(string $case): void
    {
        [$device, $seat, , $event, $order] = $this->legacyFixture();
        $payload = $event->payload_json;
        $ack = $event->result_json;
        switch ($case) {
            case 'missing_anchor':
                $order->update(['client_event_id' => null]);
                break;
            case 'missing_event':
                $event->delete();
                break;
            case 'unprocessed_event':
                $event->update(['ack_status' => 'received']);
                break;
            case 'missing_processed_time':
                $event->update(['processed_at' => null]);
                break;
            case 'wrong_event_type':
                $event->update(['event_type' => 'table.session.round']);
                break;
            case 'foreign_anchor':
                $event->update(['device_id' => $this->seatingDevice('handheld', 20, 200)->id]);
                break;
            case 'wrong_ack_uuid':
                $ack['order_uuid'] = (string) Str::uuid();
                $event->update(['result_json' => $ack]);
                break;
            case 'wrong_ack_order':
                $ack['order_id']++;
                $event->update(['result_json' => $ack]);
                break;
            case 'wrong_ack_status':
                $ack['order_status'] = 'paid';
                $event->update(['result_json' => $ack]);
                break;
            case 'wrong_payload_uuid':
                $payload['order']['uuid'] = (string) Str::uuid();
                $event->update(['payload_json' => $payload]);
                break;
            case 'wrong_payload_qty':
                $payload['order']['lines'][0]['qty'] = 3;
                $event->update(['payload_json' => $payload]);
                break;
            case 'missing_payload_lines':
                $payload['order']['lines'] = [];
                $event->update(['payload_json' => $payload]);
                break;
            case 'fractional_qty':
                $order->items()->sole()->update(['qty' => '1.500', 'line_total' => '1.500']);
                break;
            case 'changed_price':
                $order->items()->sole()->update(['unit_price_snapshot' => '1.500', 'line_total' => '3.000']);
                break;
            case 'changed_notes':
                $order->items()->sole()->update(['notes' => 'Changed held instruction']);
                break;
            case 'extra_item':
                $order->items()->sole()->replicate()->save();
                break;
            case 'discount_header':
                $order->update(['discount_total' => '0.100']);
                break;
            case 'comp_header':
                $order->update(['comp_total' => '0.100']);
                break;
            case 'discount_payload':
                $payload['order']['discount_total_baisas'] = 100;
                $event->update(['payload_json' => $payload]);
                break;
            case 'comp_payload':
                $payload['order']['comp_total_baisas'] = 100;
                $event->update(['payload_json' => $payload]);
                break;
            case 'line_discount':
                $order->items()->sole()->update(['line_discount' => '0.100']);
                break;
            case 'joined_payload':
                $payload['order']['joined_table_ids'] = [999];
                $event->update(['payload_json' => $payload]);
                break;
            case 'joined_seating':
                $this->seatingRow($this->seatingTable('Joined'), ['merged_into_id' => $seat->id, 'order_id' => $order->id]);
                break;
            case 'claim':
                $order->update(['charge_device_id' => $device->id]);
                break;
            case 'claim_amount':
                $order->update(['charge_amount_baisas' => 2000]);
                break;
            case 'declined_claim':
                $order->update(['charge_outcome' => 'declined']);
                break;
            case 'transferred_to':
                $order->update(['transferred_to_device_id' => $device->id]);
                break;
            case 'transferred_from':
                $order->update(['transferred_from_device_id' => $device->id]);
                break;
            case 'transferred_at':
                $order->update(['transferred_at' => now()]);
                break;
            case 'wrong_header':
                $order->update(['grand_total' => '9.999']);
                break;
            case 'billing':
                $seat->update(['billing_at' => now()]);
                break;
            case 'void_item':
                $order->items()->sole()->update(['status' => OrderItem::STATUS_VOID]);
                break;
            case 'missing_order_seating_link':
                $order->update(['table_session_id' => null]);
                break;
            case 'existing_round_addon_mismatch':
                [, $round] = $this->historicalRound($seat, $order, $order->items()->sole());
                $order->update(['subtotal' => '3.000', 'grand_total' => '3.000']);
                $lines = $round->priced_lines;
                $lines[0]['addons'] = [['add_on_id' => 999, 'name' => 'Unproven extra', 'price_delta_baisas' => 0]];
                $round->update(['priced_lines' => $lines]);
                break;
            case 'reserved_request_collision':
                $this->seatingRound($seat, $order, ['status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
                    'client_request_id' => 'legacy-baseline:'.$order->uuid, 'priced_lines' => []]);
                break;
            case 'pending_item_owner':
            case 'rejected_item_owner':
            case 'duplicate_item_owner':
            case 'malformed_priced_lines':
            case 'noninteger_item_id':
            case 'nested_item_id':
                $itemId = (int) $order->items()->sole()->id;
                $lines = match ($case) {
                    'malformed_priced_lines' => ['not a frozen line'],
                    'noninteger_item_id' => [['order_item_id' => (string) $itemId]],
                    'nested_item_id' => [['order_item_id' => [$itemId]]],
                    default => [['order_item_id' => $itemId]],
                };
                $status = match ($case) {
                    'pending_item_owner' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
                    'rejected_item_owner' => QrOrderRound::STATUS_REJECTED,
                    default => QrOrderRound::STATUS_ACCEPTED,
                };
                $owner = $this->seatingRound($seat, $order, ['status' => $status, 'priced_lines' => $lines]);
                if ($case === 'duplicate_item_owner') {
                    $duplicate = $owner->replicate();
                    $duplicate->fill(['round_no' => 2, 'client_request_id' => (string) Str::uuid()])->save();
                }
                break;
        }
        $before = $this->rows();
        try {
            $this->adopt($order);
            $this->fail('Unproven baseline was accepted: '.$case);
        } catch (QrDineInException $exception) {
            $this->assertSame('legacy_baseline_review_required', $exception->codeName);
            $this->assertSame(409, $exception->httpStatus);
        }
        $this->assertSame($before, $this->rows());
    }

    public static function syncWriters(): array
    {
        return [['table.session.round'], ['table.session.cancel_line']];
    }

    #[DataProvider('syncWriters')]
    public function test_sync_refusal_is_a_processed_review_result_and_changes_only_the_new_ack(string $type): void
    {
        [$device, $seat, $product, $anchor, $order] = $this->legacyFixture();
        $order->update(['grand_total' => '9.999']);
        $payload = $type === 'table.session.round'
            ? $this->staffPayload($seat, (int) $product->id, 3, (string) Str::uuid())
            : $this->cancelPayload($seat, (int) $product->id, 1, (string) Str::uuid());
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => $type,
            'client_timestamp' => now()->toIso8601String(), 'payload' => $payload];
        $before = $this->rows();
        $anchorBefore = $anchor->getRawOriginal();
        $this->app['auth']->forgetGuards();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.outcome', 'bill_unpaid')
            ->assertJsonPath('data.results.0.result.refusal_code', 'legacy_baseline_review_required')
            ->assertJsonPath('data.results.0.result.needs_review', true);
        $after = $this->rows();
        unset($before['pos_sync_events'], $after['pos_sync_events']);
        $this->assertSame($before, $after);
        $this->assertSame($anchorBefore, $anchor->fresh()->getRawOriginal());
        $ack = SyncEvent::where('client_event_id', $event['client_event_id'])->sole();
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $ack->ack_status);
        $this->assertSame('legacy_baseline_review_required', $ack->result_json['refusal_code']);
        $beforeReplay = $this->rows();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.refusal_code', 'legacy_baseline_review_required');
        $this->assertSame($beforeReplay, $this->rows());
    }

    public function test_failed_outer_transaction_rolls_back_baseline_header_and_journal_and_can_retry(): void
    {
        [, $seat, , , $order] = $this->legacyFixture();
        $this->historicalRound($seat, $order, $order->items()->sole());
        $order->update(['subtotal' => '3.000', 'grand_total' => '3.000']);
        $before = $this->rows();
        try {
            DB::transaction(function () use ($order): void {
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                app(EnsureLegacyTableBillBaselineAction::class)->handle($locked);
                $this->assertSame('5.000', $locked->fresh()->grand_total);
                throw new RuntimeException('synthetic later writer failure');
            });
            $this->fail('The synthetic downstream writer failure did not escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic later writer failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->rows());
        $this->adopt($order);
        $this->assertSame('5.000', $order->fresh()->grand_total);
        $this->assertSame(1, TableSessionEvent::where('payload->action', 'legacy_bill_baselined')->count());
    }

    public function test_cancellation_adopts_original_items_and_later_rounds_remain_counted_and_cancellable(): void
    {
        [$device, $seat, $product, , $order] = $this->legacyFixture();
        $original = $order->items()->sole();
        $cancel = $this->cancelPayload($seat, (int) $product->id, 1, 'cancel-original');
        $first = app(CancelStaffLineAction::class)->handle($device, $cancel, now(), now());
        $this->assertSame('cancelled', $first['outcome']);
        $this->assertSame(1000, $first['grand_total_baisas']);
        $this->assertSame('1.000', $original->fresh()->qty);
        $this->assertSame(1, $this->baseline($order)->priced_lines[0]['cancelled_qty']);
        $beforeReplay = $this->rows();
        $this->assertSame('replayed', app(CancelStaffLineAction::class)->handle($device, $cancel, now(), now())['outcome']);
        $this->assertSame($beforeReplay, $this->rows());

        $round = app(AppendStaffRoundAction::class)->handle($device,
            $this->staffPayload($seat, (int) $product->id, 3, 'after-cancel'), now(), now());
        $this->assertSame('4.000', $order->fresh()->grand_total);
        $second = app(CancelStaffLineAction::class)->handle($device,
            $this->cancelPayload($seat, (int) $product->id, 2, 'cancel-later'), now(), now());
        $this->assertSame(2000, $second['grand_total_baisas']);
        $this->assertSame($round['round_id'], $second['rounds'][0]['round_id']);
        $this->assertSame('1.000', $original->fresh()->qty);
        $last = app(CancelStaffLineAction::class)->handle($device,
            $this->cancelPayload($seat, (int) $product->id, 2, 'cancel-rest'), now(), now());
        $this->assertSame(0, $last['grand_total_baisas']);
        $this->assertSame('0.000', $original->fresh()->qty);
        $this->assertSame(OrderItem::STATUS_VOID, $original->fresh()->status);
        $this->assertSame(1, QrOrderRound::where('client_request_id', 'legacy-baseline:'.$order->uuid)->count());
    }

    public function test_customer_first_adoption_preserves_bill_identity_and_original_items_and_counts_both_parts(): void
    {
        [, $seat, $product, $event, $order] = $this->legacyFixture();
        $session = $this->customerSession($seat);
        $item = $order->items()->sole();
        $itemBefore = $item->getRawOriginal();
        $ackBefore = $event->getRawOriginal();
        $identityKeys = array_flip(['id', 'uuid', 'table_id', 'table_session_id', 'temp_reference', 'receipt_number', 'client_event_id', 'opened_at']);
        $identity = array_intersect_key($order->getRawOriginal(), $identityKeys);
        $payload = ['client_request_id' => 'customer-first', 'phone' => '99000000',
            'lines' => [['product_id' => (int) $product->id, 'qty' => 3, 'notes' => null, 'addon_ids' => []]]];

        $result = app(SubmitDineInQrRoundAction::class)->handle((int) $session->id, $payload, '127.0.0.1');

        $this->assertSame($identity, array_intersect_key($order->fresh()->getRawOriginal(), $identityKeys));
        $this->assertSame('5.000', $order->fresh()->grand_total);
        $this->assertSame($itemBefore, $item->fresh()->getRawOriginal());
        $this->assertSame($ackBefore, $event->fresh()->getRawOriginal());
        $this->assertSame((int) $order->id, (int) $result['order']->id);
        $this->assertBaseline($this->baseline($order), $seat, $order, (int) $item->id);
        $this->assertSame((int) $session->id, (int) $result['round']->qr_session_id);
        $this->assertSame('accepted', $result['round']->status);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_order_items', 2);
        $beforeReplay = $this->rows();
        $this->assertTrue(app(SubmitDineInQrRoundAction::class)->handle((int) $session->id, $payload, '127.0.0.1')['replayed']);
        $this->assertSame($beforeReplay, $this->rows());
    }

    public static function confirmationWriters(): array
    {
        return [['staff'], ['customer']];
    }

    #[DataProvider('confirmationWriters')]
    public function test_pending_confirmation_adopts_the_legacy_part_before_appending_frozen_children(string $writer): void
    {
        [$device, $seat, $product, , $order] = $this->legacyFixture();
        $session = $writer === 'customer' ? $this->customerSession($seat) : null;
        if ($session !== null) {
            $order->update(['qr_session_id' => $session->id, 'source' => Order::SOURCE_QR_WEB]);
        }
        $original = $order->items()->sole();
        $before = $original->getRawOriginal();
        $pending = $this->pendingRound($seat, $order, (int) $product->id, $session);
        $product->update(['name' => 'Catalogue changed', 'base_price' => '77.000', 'status' => 'inactive']);
        $this->assertSame('2.000', $order->fresh()->grand_total);
        $this->assertSame(1, $order->items()->count());

        if ($writer === 'customer') {
            app(ConfirmDineInQrRoundAction::class)->handle($device, (int) $pending->id);
        } else {
            app(ConfirmStaffRoundAction::class)->handle($device, $seat->uuid, (int) $pending->id);
        }

        $this->assertSame('5.000', $order->fresh()->grand_total);
        $this->assertSame($before, $original->fresh()->getRawOriginal());
        $this->assertSame('accepted', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->confirm_payload);
        $this->assertNotNull($pending->fresh()->accepted_seq);
        $appended = $order->items()->where('id', '!=', $original->id)->sole();
        $this->assertSame('1.000', $appended->unit_price_snapshot);
        $this->assertSame('Seating coffee', $appended->product_name_snapshot);
        $this->assertBaseline($this->baseline($order), $seat, $order, (int) $original->id);
    }

    public static function paymentBoundaries(): array
    {
        return [['finish'], ['settlement']];
    }

    #[DataProvider('paymentBoundaries')]
    public function test_finishing_or_claiming_counts_an_original_legacy_bill_before_freezing_the_amount(string $writer): void
    {
        [, $seat, , , $order] = $this->legacyFixture();
        $session = $this->customerSession($seat);
        $order->update(['qr_session_id' => $session->id, 'source' => Order::SOURCE_QR_WEB]);
        $itemsBefore = $this->rows()['pos_order_items'];
        if ($writer === 'finish') {
            $result = app(FinishDineInQrOrderAction::class)->handle((int) $session->id, 'counter');
            $this->assertSame(2000, $result['grand_total_baisas']);
            $this->assertSame(Order::STATUS_HELD, $order->fresh()->status);
        } else {
            $till = $this->seatingDevice('fixed_pos');
            app(ClaimQrSettlementAction::class)->handle($till, ['order_uuid' => $order->uuid]);
            $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
            $this->assertSame(2000, (int) $order->fresh()->charge_amount_baisas);
        }
        $this->assertSame('2.000', $order->fresh()->grand_total);
        $this->assertSame($itemsBefore, $this->rows()['pos_order_items']);
        $this->assertSame(2000, $this->baseline($order)->total_baisas);
    }

    public function test_station_cannot_claim_a_corrupted_awaiting_legacy_bill_without_its_baseline(): void
    {
        [$station, $order] = $this->awaitingHistoricalChargeFixture();
        $before = $this->rows();

        try {
            app(ClaimQrChargeAction::class)->handle($station, ['order_uuid' => $order->uuid]);
            $this->fail('The station accepted an unbaselined legacy amount.');
        } catch (QrChargeException $exception) {
            $this->assertSame('legacy_baseline_review_required', $exception->codeName);
            $this->assertSame(409, $exception->httpStatus);
        }

        $this->assertSame($before, $this->rows());
        $this->assertNull($order->fresh()->charge_amount_baisas);
        $this->assertSame(0, QrOrderRound::where('client_request_id', 'legacy-baseline:'.$order->uuid)->count());
    }

    public function test_station_claims_the_finished_baseline_amount_and_same_holder_replay_changes_nothing(): void
    {
        [, $seat, , , $order] = $this->legacyFixture();
        $session = $this->customerSession($seat);
        $station = $session->device()->firstOrFail();
        $order->update(['qr_session_id' => $session->id, 'source' => Order::SOURCE_QR_WEB]);
        $finished = app(FinishDineInQrOrderAction::class)->handle((int) $session->id, 'station');
        $this->assertSame(2000, $finished['grand_total_baisas']);
        $baselineBefore = $this->baseline($order)->getRawOriginal();
        $itemsBefore = $this->rows()['pos_order_items'];

        $claim = app(ClaimQrChargeAction::class)->handle($station, ['order_uuid' => $order->uuid]);

        $this->assertSame(2000, $claim['charge_amount_baisas']);
        $this->assertSame(2000, $claim['softpos_amount_baisas']);
        $this->assertFalse($claim['already_claimed_by_this_device']);
        $this->assertNull($order->fresh()->charge_roundup_amount_baisas);
        $this->assertSame($baselineBefore, $this->baseline($order)->getRawOriginal());
        $this->assertSame($itemsBefore, $this->rows()['pos_order_items']);
        $beforeReplay = $this->rows();
        $replay = app(ClaimQrChargeAction::class)->handle($station, ['order_uuid' => $order->uuid]);
        $this->assertSame(array_replace($claim, ['already_claimed_by_this_device' => true]), $replay);
        $this->assertSame($beforeReplay, $this->rows());
    }

    public function test_existing_live_claim_replay_preserves_its_original_amount_without_adopting_a_baseline(): void
    {
        [$station, $order] = $this->awaitingHistoricalChargeFixture();
        $order->update(['charge_device_id' => $station->id, 'charge_amount_baisas' => 3000,
            'charge_claimed_at' => now(), 'charge_deadline_at' => now()->addMinutes(3), 'charge_outcome' => null]);
        $before = $this->rows();

        $replay = app(ClaimQrChargeAction::class)->handle($station, ['order_uuid' => $order->uuid]);

        $this->assertTrue($replay['already_claimed_by_this_device']);
        $this->assertSame(3000, $replay['charge_amount_baisas']);
        $this->assertSame(3000, $replay['softpos_amount_baisas']);
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, QrOrderRound::where('client_request_id', 'legacy-baseline:'.$order->uuid)->count());
    }

    public static function unanchoredQrAccounting(): array
    {
        return ['balanced historical QR' => [true], 'orphan legacy items with round-only header' => [false]];
    }

    #[DataProvider('unanchoredQrAccounting')]
    public function test_unanchored_qr_compatibility_requires_rounds_header_and_item_gross_to_balance(bool $balanced): void
    {
        [, $seat, $product, , $order] = $this->legacyFixture();
        $qty = $balanced ? 2 : 3;
        $total = $qty * 1000;
        $order->update(['client_event_id' => null, 'source' => Order::SOURCE_QR_WEB,
            'subtotal' => $balanced ? '2.000' : '3.000', 'grand_total' => $balanced ? '2.000' : '3.000']);
        $this->seatingRound($seat, $order, ['subtotal_baisas' => $total, 'total_baisas' => $total,
            'priced_lines' => [['product_id' => (int) $product->id, 'product_name' => 'Seating coffee',
                'qty' => $qty, 'unit_price_baisas' => 1000, 'line_total_baisas' => $total,
                'line_discount_baisas' => 0, 'addons' => [], 'notes' => null]]]);
        $before = $this->rows();

        if ($balanced) {
            $this->assertNull($this->adopt($order));
        } else {
            try {
                $this->adopt($order);
                $this->fail('An orphan legacy item set passed historical QR compatibility.');
            } catch (QrDineInException $exception) {
                $this->assertSame('legacy_baseline_review_required', $exception->codeName);
                $this->assertSame(409, $exception->httpStatus);
            }
        }

        $this->assertSame($before, $this->rows());
        $this->assertSame(0, QrOrderRound::where('client_request_id', 'legacy-baseline:'.$order->uuid)->count());
    }

    private function awaitingHistoricalChargeFixture(): array
    {
        [, $seat, , , $order] = $this->legacyFixture();
        $this->historicalRound($seat, $order, $order->items()->sole());
        $session = $this->customerSession($seat);
        $session->update(['status' => QrSession::STATUS_ORDERED]);
        $seat->update(['status' => TableSession::STATUS_BILLING, 'billing_at' => now()]);
        $order->update(['qr_session_id' => $session->id, 'source' => Order::SOURCE_QR_WEB,
            'status' => Order::STATUS_AWAITING_PAYMENT, 'subtotal' => '3.000', 'grand_total' => '3.000']);

        return [$session->device()->firstOrFail(), $order];
    }

    public function test_accounting_import_replay_cannot_prove_a_new_local_staff_delta(): void
    {
        [$device, $seat, $product, , $order] = $this->legacyFixture();
        $this->adopt($order);
        $eventId = (string) Str::uuid();
        $payload = $this->staffPayload($seat, (int) $product->id, 2, 'legacy-baseline:'.$order->uuid);
        $this->app['auth']->forgetGuards();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => $eventId, 'event_type' => 'table.session.round',
            'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
        ]]])->assertOk()->assertJsonPath('data.results.0.result.outcome', 'replayed');
        $before = $this->rows();
        $this->withToken($device->plainTextToken)->getJson('/api/v1/device/tables/'.$seat->table_id.'/draft-proof?'.http_build_query([
            'order_uuid' => $order->uuid, 'kind' => 'staff_rounds', 'event_ids' => [$eventId],
        ]))->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_changed');
        $this->assertSame($before, $this->rows());
        $this->assertSame('2.000', $order->fresh()->grand_total);
    }

    public function test_missing_credential_with_accepted_legacy_rounds_is_not_a_roundless_payment_orphan(): void
    {
        [$device, $seat, , , $order] = $this->legacyFixture();
        $this->historicalRound($seat, $order, $order->items()->sole());
        $order->update(['source' => Order::SOURCE_QR_WEB, 'qr_session_id' => null, 'status' => Order::STATUS_HELD,
            'temp_reference' => 'OLD-REVIEW', 'subtotal' => '3.000', 'grand_total' => '3.000']);
        $before = $this->rows();
        try {
            app(ClaimQrSettlementAction::class)->handle($device, ['order_uuid' => $order->uuid]);
            $this->fail('A legacy bill with accepted rounds is not a roundless orphan.');
        } catch (QrChargeException $exception) {
            $this->assertSame('legacy_baseline_review_required', $exception->codeName);
            $this->assertSame(409, $exception->httpStatus);
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_refused_unknown_key_rolls_back_alias_and_journal_before_processed_ack(): void
    {
        [$device, $seat, $product, , $order] = $this->legacyFixture();
        $order->update(['grand_total' => '9.999']);
        $payload = $this->staffPayload($seat, (int) $product->id, 3, (string) Str::uuid());
        $payload['seating_key'] = (string) Str::uuid();
        $before = $this->rows();
        $this->app['auth']->forgetGuards();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
            'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.outcome', 'bill_unpaid')
            ->assertJsonPath('data.results.0.result.event_id', null);
        $after = $this->rows();
        unset($before['pos_sync_events'], $after['pos_sync_events']);
        $this->assertSame($before, $after);
    }

    private function legacyFixture(): array
    {
        $device = $this->seatingDevice('handheld');
        $table = $this->seatingTable('Legacy table');
        $product = $this->seatingProduct();
        $uuid = (string) Str::uuid();
        $eventId = (string) Str::uuid();
        $event = ['client_event_id' => $eventId, 'event_type' => 'order.hold', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => ['uuid' => $uuid, 'table_id' => $table->id, 'order_type' => 'dine_in', 'source' => 'handheld',
                'opened_at' => now()->toIso8601String(), 'subtotal_baisas' => 2000, 'discount_total_baisas' => 0,
                'tax_total_baisas' => 0, 'grand_total_baisas' => 2000,
                'lines' => [['product_id' => $product->id, 'qty' => 2, 'unit_price_baisas' => 1000, 'line_total_baisas' => 2000]],
            ]]];
        $this->app['auth']->forgetGuards();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $order = Order::where('uuid', $uuid)->sole();
        $seat = $this->seatingRow($table, ['order_id' => $order->id, 'opened_by_device_id' => $device->id]);
        $order->update(['status' => Order::STATUS_OPEN, 'table_session_id' => $seat->id]);

        return [$device, $seat, $product, SyncEvent::where('client_event_id', $eventId)->sole(), $order];
    }

    private function staffPayload(TableSession $seat, int $productId, int $qty, string $request): array
    {
        return ['seating_key' => $seat->client_request_id, 'table_id' => (int) $seat->table_id, 'queued_offline' => false,
            'client_request_id' => $request, 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $productId, 'qty' => $qty, 'notes' => null, 'addon_ids' => []]]];
    }

    private function cancelPayload(TableSession $seat, int $productId, int $qty, string $request): array
    {
        return ['seating_key' => $seat->client_request_id, 'table_id' => (int) $seat->table_id, 'queued_offline' => false,
            'client_request_id' => $request, 'product_id' => $productId, 'qty' => $qty, 'addon_ids' => [], 'notes' => null,
            'prepared' => false, 'reason' => 'Cashier correction', 'authorized_by' => 'Manager', 'cancelled_at' => now()->toIso8601String()];
    }

    private function historicalRound(TableSession $seat, Order $order, OrderItem $original): array
    {
        $later = $original->replicate();
        $later->fill(['qty' => '3.000', 'line_total' => '3.000'])->save();
        $round = $this->seatingRound($seat, $order, ['subtotal_baisas' => 3000, 'total_baisas' => 3000,
            'priced_lines' => [['line_index' => 0, 'product_id' => (int) $later->product_id,
                'product_name' => $later->product_name_snapshot, 'qty' => 3, 'notes' => null, 'addons' => [],
                'unit_price_baisas' => 1000, 'base_price_baisas' => 1000, 'line_discount_baisas' => 0,
                'line_total_baisas' => 3000, 'order_item_id' => (int) $later->id]]]);

        return [$later, $round];
    }

    private function customerSession(TableSession $seat): QrSession
    {
        $station = $this->seatingDevice('payment_station');

        return QrSession::create(['uuid' => (string) Str::uuid(), 'company_id' => $seat->company_id,
            'branch_id' => $seat->branch_id, 'device_id' => $station->id, 'table_id' => $seat->table_id,
            'table_session_id' => $seat->id, 'token' => hash('sha256', (string) Str::uuid()),
            'status' => QrSession::STATUS_ACTIVE, 'expires_at' => now()->addHours(6), 'token_expires_at' => now()->addHours(6),
            'bound_at' => now(), 'client_secret_hash' => QrSession::hashClientSecret('baseline-test-secret')]);
    }

    private function pendingRound(TableSession $seat, Order $order, int $productId, ?QrSession $session): QrOrderRound
    {
        $loaded = app(LoadQrPricingInputAction::class)->handle((int) $order->company_id, (int) $order->branch_id,
            [['product_id' => $productId, 'qty' => 3, 'notes' => null, 'addon_ids' => []]], DateTimeImmutable::createFromInterface(now()));
        $price = Totals::priceOrder($loaded->pricingInput);
        $context = $session ?? new QrSession(['company_id' => $order->company_id]);

        return $this->seatingRound($seat, $order, ['qr_session_id' => $session?->id,
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION, 'resolved_at' => null,
            'priced_lines' => app(FreezeQrRoundLinesAction::class)->handle($loaded, $price),
            'confirm_payload' => app(AppendQrPricedLinesAction::class)->buildPayload($context, $loaded, $price, now()),
            'subtotal_baisas' => 3000, 'tax_baisas' => 0, 'total_baisas' => 3000]);
    }

    private function adopt(Order $order): ?QrOrderRound
    {
        return DB::transaction(fn (): ?QrOrderRound => app(EnsureLegacyTableBillBaselineAction::class)
            ->handle(Order::whereKey($order->id)->lockForUpdate()->firstOrFail()));
    }

    private function baseline(Order $order): QrOrderRound
    {
        return QrOrderRound::where('order_id', $order->id)->where('client_request_id', 'legacy-baseline:'.$order->uuid)->sole();
    }

    private function assertBaseline(QrOrderRound $round, TableSession $seat, Order $order, int $itemId): void
    {
        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $round->status);
        $this->assertNull($round->qr_session_id);
        $this->assertNull($round->accepted_seq);
        $this->assertNull($round->confirm_payload);
        $this->assertSame((int) $order->id, (int) $round->order_id);
        $this->assertSame((int) $seat->id, (int) $round->table_session_id);
        $this->assertSame('legacy-baseline:'.$order->uuid, $round->client_request_id);
        $this->assertCount(1, $round->priced_lines);
        $this->assertSame($itemId, $round->priced_lines[0]['order_item_id']);
        $this->assertTrue($round->priced_lines[0]['accounting_only']);
        $this->assertSame(2, $round->priced_lines[0]['qty']);
        $this->assertSame(2000, $round->subtotal_baisas);
        $this->assertSame(0, $round->tax_baisas);
        $this->assertSame(2000, $round->total_baisas);
    }

    private function billIdentity(Order $order): array
    {
        return array_intersect_key($order->getRawOriginal(), array_flip(['id', 'uuid', 'company_id', 'branch_id', 'device_id', 'source', 'table_id',
            'table_session_id', 'qr_session_id', 'client_event_id', 'client_request_id', 'receipt_number',
            'temp_reference', 'customer_id', 'plate_number', 'opened_at']));
    }

    private function withoutAccountingRows(array $rows): array
    {
        unset($rows['pos_qr_order_rounds'], $rows['pos_table_session_events']);

        return $rows;
    }

    private function rows(): array
    {
        $rows = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'pos_%' ORDER BY name") as $table) {
            $records = DB::table($table->name)->get()->map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($records);
            $rows[$table->name] = $records;
        }

        return $rows;
    }
}
