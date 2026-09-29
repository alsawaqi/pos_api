<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\SyncEvent;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class TableDraftProofTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-12 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
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

    private function proof(Device $device, Table $table, array $input): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->plainTextToken)->getJson('/api/v1/device/tables/'.$table->id.'/draft-proof?'.http_build_query($input));
    }

    private function roundEvent(Device $device, TableSession $seat, int $productId, int $qty = 1): SyncEvent
    {
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
            'client_timestamp' => now()->toIso8601String(), 'payload' => [
                'seating_key' => $seat->client_request_id, 'table_id' => $seat->table_id, 'queued_offline' => false,
                'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
                'lines' => [['product_id' => $productId, 'qty' => $qty, 'notes' => 'Keep exactly', 'addon_ids' => []]],
            ]];
        $this->app['auth']->forgetGuards();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')->assertJsonPath('data.results.0.result.outcome', 'appended');

        return SyncEvent::where('client_event_id', $event['client_event_id'])->sole();
    }

    private function staffFixture(string $type = 'fixed_pos'): array
    {
        $device = $this->seatingDevice($type);
        $table = $this->seatingTable('T1');
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $event = $this->roundEvent($device, $seat, $product->id, 2);
        $order = Order::findOrFail($seat->fresh()->order_id);
        $input = ['order_uuid' => $order->uuid, 'kind' => 'staff_rounds', 'event_ids' => [$event->client_event_id]];

        return [$device, $table, $seat, $product, $event, $order, $input];
    }

    public static function devices(): array
    {
        return ['till' => ['fixed_pos'], 'handheld' => ['handheld']];
    }

    #[DataProvider('devices')]
    public function test_literal_proof_uses_ack_and_item_ids_and_writes_nothing(string $type): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->staffFixture($type);
        $round = QrOrderRound::findOrFail($event->result_json['round_id']);
        $item = $order->items()->sole();
        $order->update(['plate_number' => 'PRIVATE-PLATE', 'note' => 'PRIVATE-NOTE']);
        $before = $this->rows();
        $response = $this->proof($device, $table, $input)->assertOk()
            ->assertJsonPath('data.proof_policy', 'same_bill_draft_v1')->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.archive_authorized', false)->assertJsonPath('data.delta_policy', 'proven_local_rounds_only')
            ->assertJsonPath('data.device_id', $device->id)->assertJsonPath('data.order_uuid', $order->uuid)
            ->assertJsonPath('data.table_session_uuid', $seat->uuid)->assertJsonPath('data.bill.grand_total_baisas', 2000)
            ->assertJsonPath('data.acknowledged', [[
                'client_event_id' => $event->client_event_id, 'client_request_id' => $round->client_request_id,
                'seating_key' => $seat->client_request_id, 'round_id' => $round->id, 'round_no' => 1,
                'lines' => [['order_item_id' => $item->id, 'product_id' => $product->id, 'qty' => 2,
                    'name' => 'Seating coffee', 'notes' => 'Keep exactly', 'unit_price_baisas' => 1000,
                    'line_total_baisas' => 2000, 'addons' => []]],
            ]])->assertJsonMissingPath('data.bill.plate_number')->assertJsonMissingPath('data.bill.customer_id')
            ->assertJsonMissingPath('data.bill.note')->assertJsonMissingPath('data.acknowledged.0.payload_json');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->rows());
        $this->assertContains('throttle:qr-table-device-read', Route::getRoutes()->getByName('device.tables.draft-proof')->gatherMiddleware());
        if ($type === 'fixed_pos') {
            fwrite(STDOUT, "\nUNIFIED_DRAFT_PROOF_JSON=".json_encode($response->json(), JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function test_customer_and_other_device_rounds_never_become_the_local_acknowledged_subset(): void
    {
        $device = $this->seatingDevice();
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable('T1');
        $product = $this->seatingProduct();
        $opened = app(OpenDineInTableAction::class)->handle($station, $table->id);
        $session = app(BindQrTableSessionAction::class)->handle($opened['table_token'], 'synthetic-secret');
        $seat = TableSession::findOrFail($session->table_session_id);
        // Station-origin seat has no staff key until a staff request attaches.
        $seat->update(['client_request_id' => (string) Str::uuid()]);
        $event = $this->roundEvent($device, $seat, $product->id, 2);
        app(SubmitDineInQrRoundAction::class)->handle($session->id, [
            'client_request_id' => 'customer-adoption', 'phone' => '99000000',
            'lines' => [['product_id' => $product->id, 'qty' => 3, 'notes' => null, 'addon_ids' => []]],
        ], '127.0.0.1');
        $customer = QrOrderRound::where('qr_session_id', $session->id)->sole();
        $this->assertSame('accepted', $customer->status);
        $other = $this->seatingDevice('handheld');
        $this->roundEvent($other, $seat, $product->id, 4);
        $order = Order::sole();
        $this->assertSame('qr_web', $order->source);
        $this->assertSame($station->id, $order->device_id);
        $before = $this->rows();
        $this->proof($device, $table, ['order_uuid' => $order->uuid, 'kind' => 'staff_rounds', 'event_ids' => [$event->client_event_id]])
            ->assertOk()->assertJsonCount(1, 'data.acknowledged')->assertJsonPath('data.acknowledged.0.lines.0.qty', 2)
            ->assertJsonPath('data.bill.grand_total_baisas', 9000)->assertJsonCount(3, 'data.bill.items');
        $this->proof($other, $table, ['order_uuid' => $order->uuid, 'kind' => 'staff_rounds', 'event_ids' => [$event->client_event_id]])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_missing');
        $this->assertSame($before, $this->rows());
    }

    public function test_qr_source_or_a_station_header_alone_cannot_bypass_the_owner_guard(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->staffFixture();
        $station = $this->seatingDevice('payment_station');
        $order->update(['source' => 'qr_web', 'device_id' => $station->id]);
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_owner_required');
        $this->assertSame($before, $this->rows());
    }

    public function test_two_event_ids_for_the_same_round_are_not_counted_twice(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->staffFixture();
        $replay = $event->replicate();
        $replay->client_event_id = (string) Str::uuid();
        $replay->save();
        $input['event_ids'][] = $replay->client_event_id;
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_changed');
        $this->assertSame($before, $this->rows());
    }

    public function test_multiple_valid_rounds_have_deterministic_order_and_no_double_count(): void
    {
        [$device, $table, $seat, $product, $first, $order, $input] = $this->staffFixture();
        $second = $this->roundEvent($device, $seat, $product->id, 3);
        $input['event_ids'][] = $second->client_event_id;
        $ids = $input['event_ids'];
        sort($ids, SORT_STRING);
        $before = $this->rows();
        $response = $this->proof($device, $table, $input)->assertOk()->assertJsonCount(2, 'data.acknowledged');
        $this->assertSame($ids, array_column($response->json('data.acknowledged'), 'client_event_id'));
        $this->assertSame(5, array_sum(array_map(static fn (array $ack): int => $ack['lines'][0]['qty'], $response->json('data.acknowledged'))));
        $input['event_ids'] = array_reverse($input['event_ids']);
        $this->proof($device, $table, $input)->assertOk()->assertExactJson($response->json());
        $this->assertSame($before, $this->rows());
    }

    public static function seatingGuards(): array
    {
        return [['billing'], ['joined'], ['archived'], ['pending_round'], ['extra_bill'], ['fractional'], ['bad_header']];
    }

    #[DataProvider('seatingGuards')]
    public function test_unsupported_local_reconciliation_keeps_all_rows(string $case): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->staffFixture();
        $code = 'draft_proof_ineligible';
        switch ($case) {
            case 'billing':
                $seat->update(['billing_at' => now()]);
                break;
            case 'joined':
                $this->seatingRow($this->seatingTable('T2'), ['merged_into_id' => $seat->id, 'order_id' => $order->id]);
                break;
            case 'archived':
                $table->delete();
                break;
            case 'pending_round':
                QrOrderRound::findOrFail($event->result_json['round_id'])->update(['status' => 'pending_confirmation']);
                break;
            case 'extra_bill':
                $copy = $order->replicate();
                $copy->uuid = (string) Str::uuid();
                $copy->table_session_id = null;
                $copy->save();
                $code = 'table_bill_conflict';
                break;
            case 'fractional':
                $order->items()->sole()->update(['qty' => '1.500', 'line_total' => '1.500']);
                $code = 'draft_proof_evidence_changed';
                break;
            case 'bad_header':
                $order->update(['grand_total' => '9.999']);
                $code = 'draft_proof_evidence_changed';
                break;
        }
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', $code);
        $this->assertSame($before, $this->rows());
    }

    public static function evidenceDefects(): array
    {
        return array_map(static fn (string $field): array => [$field], [
            'missing_event', 'received_event', 'failed_event', 'no_processed_time', 'wrong_event_device', 'wrong_event_type',
            'wrong_ack_bill', 'wrong_ack_seating', 'wrong_ack_table', 'wrong_ack_round', 'wrong_ack_number', 'wrong_ack_total',
            'wrong_request_id', 'wrong_request_table', 'wrong_request_key', 'wrong_request_qty', 'wrong_request_notes',
            'wrong_item_id', 'duplicate_item_owner', 'missing_item', 'changed_price', 'changed_name', 'changed_qty',
            'cancelled_line', 'held_line', 'round_not_accepted', 'merged_round', 'discount',
        ]);
    }

    #[DataProvider('evidenceDefects')]
    public function test_unproven_or_changed_history_refuses_without_writes(string $defect): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->staffFixture();
        $round = QrOrderRound::findOrFail($event->result_json['round_id']);
        $item = $order->items()->sole();
        $payload = $event->payload_json;
        $ack = $event->result_json;
        $lines = $round->priced_lines;
        $code = 'draft_proof_evidence_changed';
        switch ($defect) {
            case 'missing_event': $event->delete();
                $code = 'draft_proof_evidence_missing';
                break;
            case 'received_event': $event->update(['ack_status' => 'received']);
                $code = 'draft_proof_evidence_missing';
                break;
            case 'failed_event': $event->update(['ack_status' => 'failed']);
                $code = 'draft_proof_evidence_missing';
                break;
            case 'no_processed_time': $event->update(['processed_at' => null]);
                $code = 'draft_proof_evidence_missing';
                break;
            case 'wrong_event_device': $event->update(['device_id' => $this->seatingDevice('handheld')->id]);
                $code = 'draft_proof_evidence_missing';
                break;
            case 'wrong_event_type': $event->update(['event_type' => 'order.hold']);
                $code = 'draft_proof_evidence_missing';
                break;
            case 'wrong_ack_bill': $ack['order_uuid'] = (string) Str::uuid();
                $event->update(['result_json' => $ack]);
                break;
            case 'wrong_ack_seating': $ack['table_session_uuid'] = (string) Str::uuid();
                $event->update(['result_json' => $ack]);
                break;
            case 'wrong_ack_table': $ack['table_id']++;
                $event->update(['result_json' => $ack]);
                break;
            case 'wrong_ack_round': $ack['round_id']++;
                $event->update(['result_json' => $ack]);
                break;
            case 'wrong_ack_number': $ack['round_no']++;
                $event->update(['result_json' => $ack]);
                break;
            case 'wrong_ack_total': $ack['total_baisas']++;
                $event->update(['result_json' => $ack]);
                break;
            case 'wrong_request_id': $payload['client_request_id'] = (string) Str::uuid();
                $event->update(['payload_json' => $payload]);
                break;
            case 'wrong_request_table': $payload['table_id']++;
                $event->update(['payload_json' => $payload]);
                break;
            case 'wrong_request_key': $payload['seating_key'] = (string) Str::uuid();
                $event->update(['payload_json' => $payload]);
                break;
            case 'wrong_request_qty': $payload['lines'][0]['qty']++;
                $event->update(['payload_json' => $payload]);
                break;
            case 'wrong_request_notes': $payload['lines'][0]['notes'] = 'Changed';
                $event->update(['payload_json' => $payload]);
                break;
            case 'wrong_item_id': $lines[0]['order_item_id']++;
                $round->update(['priced_lines' => $lines]);
                break;
            case 'duplicate_item_owner': $this->seatingRound($seat, $order, ['round_no' => 2, 'priced_lines' => $lines]);
                break;
            case 'missing_item': $item->delete();
                break;
            case 'changed_price': $item->update(['unit_price_snapshot' => '2.000', 'line_total' => '4.000']);
                break;
            case 'changed_name': $item->update(['product_name_snapshot' => 'Different']);
                break;
            case 'changed_qty': $item->update(['qty' => 3, 'line_total' => '3.000']);
                break;
            case 'cancelled_line': $lines[0]['cancellations'] = [['qty' => 1]];
                $round->update(['priced_lines' => $lines]);
                break;
            case 'held_line': $lines[0]['held_reason'] = 'missing';
                $round->update(['priced_lines' => $lines]);
                break;
            case 'round_not_accepted': $round->update(['status' => 'rejected']);
                break;
            case 'merged_round': $round->update(['origin_table_session_id' => $seat->id]);
                break;
            case 'discount': $order->update(['discount_total' => '0.001']);
                $code = 'draft_proof_ineligible';
                break;
        }
        if ($defect === 'missing_item') {
            $code = 'draft_proof_ineligible';
        }
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', $code);
        $this->assertSame($before, $this->rows());
    }

    public static function billGuards(): array
    {
        return ['held' => [['status' => 'held']], 'paid' => [['status' => 'paid']], 'void' => [['status' => 'void']],
            'closed' => [['closed_at' => '2026-09-12 12:00:00']], 'claim' => [['charge_device_id' => 1]],
            'declined residue' => [['charge_outcome' => 'declined']], 'transfer pending' => [['transferred_to_device_id' => 1]],
            'transfer history' => [['transferred_from_device_id' => 1]], 'transfer time' => [['transferred_at' => '2026-09-12 12:00:00']]];
    }

    #[DataProvider('billGuards')]
    public function test_payment_or_transfer_evidence_never_authorizes_reconciliation(array $change): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->staffFixture();
        $order->update($change);
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_ineligible');
        $this->assertSame($before, $this->rows());
    }

    public function test_other_device_or_tenant_cannot_read_the_local_proof(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->staffFixture();
        $other = $this->seatingDevice('handheld');
        $foreign = $this->seatingDevice('handheld', 20, 200);
        $before = $this->rows();
        $this->proof($other, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_owner_required');
        $this->proof($foreign, $table, $input)->assertNotFound()->assertJsonPath('errors.0.code', 'table_not_found');
        $this->assertSame($before, $this->rows());
    }

    public function test_station_refusal_precedes_table_lookup_and_input_validation(): void
    {
        $device = $this->seatingDevice('payment_station');
        $table = new Table(['id' => 999]);
        $before = $this->rows();
        $this->proof($device, $table, [])->assertStatus(409)->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->assertSame($before, $this->rows());
    }

    public function test_wrong_bill_uuid_is_not_silently_retargeted(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->staffFixture();
        $input['order_uuid'] = (string) Str::uuid();
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_bill_changed');
        $this->assertSame($before, $this->rows());
    }

    public function test_duplicate_ack_ids_are_rejected_before_counting_the_same_line_twice(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->staffFixture();
        $input['event_ids'][] = $event->client_event_id;
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertUnprocessable();
        $this->assertSame($before, $this->rows());
    }

    private function legacyFixture(): array
    {
        $device = $this->seatingDevice('handheld');
        $table = $this->seatingTable('T1');
        $product = $this->seatingProduct();
        $uuid = (string) Str::uuid();
        $id = (string) Str::uuid();
        $event = ['client_event_id' => $id, 'event_type' => 'order.hold', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => ['uuid' => $uuid, 'table_id' => $table->id, 'order_type' => 'dine_in', 'source' => 'handheld',
                'opened_at' => now()->toIso8601String(), 'subtotal_baisas' => 2000, 'discount_total_baisas' => 0,
                'tax_total_baisas' => 0, 'grand_total_baisas' => 2000,
                'lines' => [['product_id' => $product->id, 'qty' => 2, 'unit_price_baisas' => 1000, 'line_total_baisas' => 2000]],
            ]]];
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $order = Order::sole();
        // Historical same-bill link, not a second bill or a combine. The real
        // legacy writer supplied the item snapshots and immutable hold ACK.
        $seat = $this->seatingRow($table, ['order_id' => $order->id, 'opened_by_device_id' => $device->id]);
        $order->update(['status' => 'open', 'table_session_id' => $seat->id]);

        return [$device, $table, $seat, $product, SyncEvent::where('client_event_id', $id)->sole(), $order,
            ['order_uuid' => $uuid, 'kind' => 'legacy_hold']];
    }

    public static function malformedProofs(): array
    {
        return [['header_tax'], ['round_line_money'], ['round_subtotal'], ['round_tax'], ['array_round_id'],
            ['null_request_line'], ['string_request_line'], ['object_lines'], ['nested_addon_id']];
    }

    #[DataProvider('malformedProofs')]
    public function test_malformed_or_monetarily_inconsistent_acknowledgements_are_evidence_refusals(string $case): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->staffFixture();
        $payload = $event->payload_json;
        $ack = $event->result_json;
        $round = QrOrderRound::findOrFail($ack['round_id']);
        switch ($case) {
            case 'header_tax':
                $order->update(['tax_total' => '0.500', 'grand_total' => '2.500']);
                break;
            case 'round_line_money':
                $lines = $round->priced_lines;
                $lines[0]['unit_price_baisas'] = 2000;
                $lines[0]['line_total_baisas'] = 4000;
                $round->update(['priced_lines' => $lines]);
                $order->items()->sole()->update(['unit_price_snapshot' => '2.000', 'line_total' => '4.000']);
                $order->update(['subtotal' => '4.000', 'grand_total' => '4.000']);
                break;
            case 'round_subtotal':
                $round->update(['subtotal_baisas' => 1000]);
                break;
            case 'round_tax':
                $round->update(['tax_baisas' => 500]);
                break;
            case 'array_round_id':
                $ack['round_id'] = [];
                $event->update(['result_json' => $ack]);
                break;
            case 'null_request_line':
                $payload['lines'] = [null];
                $event->update(['payload_json' => $payload]);
                break;
            case 'string_request_line':
                $payload['lines'] = ['not a line'];
                $event->update(['payload_json' => $payload]);
                break;
            case 'object_lines':
                $payload['lines'] = ['wrong-key' => $payload['lines'][0]];
                $event->update(['payload_json' => $payload]);
                break;
            case 'nested_addon_id':
                $payload['lines'][0]['addon_ids'] = [[]];
                $event->update(['payload_json' => $payload]);
                break;
        }
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_changed');
        $this->assertSame($before, $this->rows());
    }

    public function test_legacy_hold_evidence_never_authorizes_archive_or_a_delta(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->legacyFixture();
        $originalId = $order->items()->sole()->id;
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertOk()->assertJsonPath('data.delta_policy', 'blocked_until_baseline_adoption')
            ->assertJsonPath('data.archive_authorized', false)->assertJsonPath('data.acknowledged.0.client_event_id', $event->client_event_id)
            ->assertJsonCount(1, 'data.acknowledged.0.lines')->assertJsonPath('data.acknowledged.0.lines.0.order_item_id', $originalId)
            ->assertJsonPath('data.acknowledged.0.lines.0.qty', 2)->assertJsonPath('data.bill.grand_total_baisas', 2000);
        $this->assertSame($before, $this->rows());
    }

    public function test_round_only_totals_cannot_be_used_to_hide_an_unowned_legacy_baseline(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->legacyFixture();
        $this->roundEvent($device, $seat, $product->id, 3);
        // Owner-approved 4C4B correction: a frozen baseline now contributes
        // the original 2.000 before the new 3.000 round is appended. Client
        // archival remains a separate, deliberately unavailable permission.
        $this->assertSame(5000, (int) round((float) $order->items()->sum('line_total') * 1000));
        $this->assertSame('5.000', (string) $order->fresh()->grand_total);
        fwrite(STDOUT, "\nUNIFIED_LEGACY_BASELINE_GAP=".json_encode([
            'original_legacy_baisas' => 2000, 'new_round_baisas' => 3000,
            'item_rows_baisas' => 5000, 'observed_header_baisas' => 5000,
            'proof_refusal' => 'draft_proof_evidence_changed',
        ], JSON_THROW_ON_ERROR)."\n");
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_changed');
        $this->assertSame($before, $this->rows());
    }

    public static function legacyDefects(): array
    {
        return [['missing_anchor'], ['foreign_anchor'], ['wrong_uuid'], ['wrong_qty'], ['changed_item'], ['extra_item'],
            ['addons_scalar'], ['addons_null_entry'], ['addons_missing_id'], ['addons_invalid_amount'], ['addons_duplicate']];
    }

    #[DataProvider('legacyDefects')]
    public function test_legacy_hold_needs_the_original_unchanged_baseline(string $defect): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->legacyFixture();
        $code = 'draft_proof_evidence_changed';
        if ($defect === 'missing_anchor') {
            $order->update(['client_event_id' => null]);
            $code = 'draft_proof_evidence_missing';
        } elseif ($defect === 'foreign_anchor') {
            $event->update(['device_id' => $this->seatingDevice()->id]);
            $code = 'draft_proof_evidence_missing';
        } elseif ($defect === 'wrong_uuid' || $defect === 'wrong_qty') {
            $payload = $event->payload_json;
            if ($defect === 'wrong_uuid') {
                $payload['order']['uuid'] = (string) Str::uuid();
            } else {
                $payload['order']['lines'][0]['qty']++;
            }
            $event->update(['payload_json' => $payload]);
        } elseif (str_starts_with($defect, 'addons_')) {
            $payload = $event->payload_json;
            $payload['order']['lines'][0]['addons'] = match ($defect) {
                'addons_scalar' => 'not a list',
                'addons_null_entry' => [null],
                'addons_missing_id' => [['price_delta_baisas' => 0]],
                'addons_invalid_amount' => [['add_on_id' => 1, 'price_delta_baisas' => []]],
                'addons_duplicate' => [['add_on_id' => 1], ['add_on_id' => 1]],
            };
            $event->update(['payload_json' => $payload]);
        } elseif ($defect === 'changed_item') {
            $order->items()->sole()->update(['notes' => 'Not the held notes']);
        } else {
            $order->items()->sole()->replicate()->save();
        }
        $before = $this->rows();
        $this->proof($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', $code);
        $this->assertSame($before, $this->rows());
    }
}
