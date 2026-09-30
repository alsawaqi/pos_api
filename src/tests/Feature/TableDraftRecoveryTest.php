<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\SyncEvent;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
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

final class TableDraftRecoveryTest extends TestCase
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

    private function preview(Device $device, Table $table, array $input): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->plainTextToken)->getJson('/api/v1/device/tables/'.$table->id.'/draft-recovery?'.http_build_query($input));
    }

    private function finalize(Device $device, Table $table, array $input): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->plainTextToken)->postJson('/api/v1/device/tables/'.$table->id.'/draft-recovery', $input);
    }

    private function intent(Device $device, Table $table, array $input): array
    {
        return $input + ['client_request_id' => (string) Str::uuid(), 'local_snapshot_hash' => hash('sha256', 'exact-local-rows'),
            'preview_token' => $this->preview($device, $table, $input)->assertOk()->json('data.preview_token')];
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

    private function fixture(string $type = 'fixed_pos', bool $legacy = false): array
    {
        $device = $this->seatingDevice($type);
        $table = $this->seatingTable('T1');
        $product = $this->seatingProduct();
        if ($legacy) {
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
            $seat = $this->seatingRow($table, ['order_id' => $order->id, 'opened_by_device_id' => $device->id]);
            $order->update(['status' => 'open', 'table_session_id' => $seat->id]);
            $event = SyncEvent::where('client_event_id', $id)->sole();
            $input = ['order_uuid' => $uuid, 'kind' => 'legacy_hold'];
        } else {
            $seat = $this->seatingRow($table, ['opened_by_device_id' => $device->id]);
            $event = $this->roundEvent($device, $seat, $product->id, 2);
            $order = Order::findOrFail($seat->fresh()->order_id);
            $input = ['order_uuid' => $order->uuid, 'kind' => 'staff_rounds', 'event_ids' => [$event->client_event_id]];
        }

        return [$device, $table, $seat, $product, $event, $order, $input];
    }

    public static function devices(): array
    {
        return [['fixed_pos'], ['handheld']];
    }

    #[DataProvider('devices')]
    public function test_preview_is_literal_read_only_and_does_not_authorize_local_archival(string $type): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture($type);
        $before = $this->rows();
        $response = $this->preview($device, $table, $input)->assertOk()
            ->assertJsonPath('data.recovery_policy', 'same_bill_recovery_v1')
            ->assertJsonPath('data.expires_at', '2026-09-12T12:05:00+00:00')
            ->assertJsonPath('data.proof.proof_policy', 'same_bill_draft_v1')
            ->assertJsonPath('data.proof.read_only', true)->assertJsonPath('data.proof.archive_authorized', false)
            ->assertJsonPath('data.proof.delta_policy', 'proven_local_rounds_only')
            ->assertJsonPath('data.proof.order_uuid', $order->uuid)->assertJsonPath('data.proof.table_session_uuid', $seat->uuid)
            ->assertJsonPath('data.proof.bill.grand_total_baisas', 2000)
            ->assertJsonPath('data.proof.acknowledged.0.client_event_id', $event->client_event_id)
            ->assertJsonPath('data.proof.acknowledged.0.lines.0.order_item_id', $order->items()->sole()->id);
        $this->assertMatchesRegularExpression('/^[0-9]+\.[a-f0-9]{64}$/', $response->json('data.preview_token'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->rows());
        $this->assertContains('throttle:qr-table-device-read', Route::getRoutes()->getByName('device.tables.draft-recovery.preview')->gatherMiddleware());
        $this->assertContains('throttle:qr-table-device-write', Route::getRoutes()->getByName('device.tables.draft-recovery.store')->gatherMiddleware());
        if ($type === 'fixed_pos') {
            fwrite(STDOUT, "\nSAME_BILL_RECOVERY_PREVIEW=".json_encode($response->json(), JSON_THROW_ON_ERROR)."\n");
        }
    }

    #[DataProvider('devices')]
    public function test_finalize_only_adds_an_immutable_receipt_and_never_appends_or_pays(string $type): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture($type);
        $intent = $this->intent($device, $table, $input);
        $before = $this->rows();
        $result = ['outcome' => 'draft_recovered', 'recovery_policy' => 'same_bill_recovery_v1',
            'client_request_id' => $intent['client_request_id'], 'local_snapshot_hash' => $intent['local_snapshot_hash'],
            'order_uuid' => $order->uuid, 'table_id' => $table->id, 'table_session_uuid' => $seat->uuid,
            'preview_token' => $intent['preview_token'], 'archive_authorized' => true];
        $response = $this->finalize($device, $table, $intent)->assertOk()->assertJsonPath('data.status', 'processed');
        $journal = TableSessionEvent::where('payload->action', 'same_bill_draft_recovered')->sole();
        $response->assertJsonPath('data.result', $result + ['event_id' => $journal->id]);
        $this->assertSame('attached', $journal->event_type);
        $this->assertSame($result, $journal->payload['result']);
        $after = $this->rows();
        unset($before['pos_table_session_events'], $after['pos_table_session_events']);
        $this->assertSame($before, $after);
        $before = $this->rows();
        $this->finalize($device, $table, $intent)->assertOk()->assertExactJson($response->json());
        $this->assertSame($before, $this->rows());
        if ($type === 'fixed_pos') {
            fwrite(STDOUT, "\nSAME_BILL_RECOVERY_ACK=".json_encode($response->json(), JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function test_legacy_baseline_owns_original_ids_once_and_replay_returns_recovery_not_baseline_event_id(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture('handheld', true);
        $items = DB::table('pos_order_items')->get()->map(static fn ($row): array => (array) $row)->all();
        $header = $order->getRawOriginal();
        $before = $this->rows();
        $intent = $this->intent($device, $table, $input);
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, QrOrderRound::count());
        $response = $this->finalize($device, $table, $intent)->assertOk();
        $round = QrOrderRound::sole();
        $this->assertSame('legacy-baseline:'.$order->uuid, $round->client_request_id);
        $this->assertTrue($round->priced_lines[0]['accounting_only']);
        $this->assertSame($items[0]['id'], $round->priced_lines[0]['order_item_id']);
        $this->assertNull($round->accepted_seq);
        $this->assertNull($round->confirm_payload);
        $this->assertSame($items, DB::table('pos_order_items')->get()->map(static fn ($row): array => (array) $row)->all());
        $this->assertSame($header, $order->fresh()->getRawOriginal());
        $journal = TableSessionEvent::where('payload->action', 'same_bill_draft_recovered')->sole();
        $baselineJournal = TableSessionEvent::where('payload->action', 'legacy_bill_baselined')->sole();
        $this->assertGreaterThan($baselineJournal->id, $journal->id);
        $response->assertJsonPath('data.result.event_id', $journal->id);
        $before = $this->rows();
        $this->finalize($device, $table, $intent)->assertOk()->assertExactJson($response->json());
        $this->assertSame($before, $this->rows());
        $this->preview($device, $table, $input)->assertOk()
            ->assertJsonPath('data.proof.acknowledged.0.lines.0.order_item_id', $items[0]['id'])
            ->assertJsonPath('data.proof.acknowledged.0.client_event_id', $event->client_event_id);
        $this->assertSame($before, $this->rows());
    }

    public function test_already_baselined_legacy_proof_keeps_only_original_held_items(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture('handheld', true);
        $originalId = $order->items()->sole()->id;
        $this->roundEvent($device, $seat, $product->id, 3);
        $before = $this->rows();
        $this->preview($device, $table, $input)->assertOk()->assertJsonPath('data.proof.bill.grand_total_baisas', 5000)
            ->assertJsonCount(1, 'data.proof.acknowledged')->assertJsonCount(1, 'data.proof.acknowledged.0.lines')
            ->assertJsonPath('data.proof.acknowledged.0.lines.0.order_item_id', $originalId)
            ->assertJsonPath('data.proof.acknowledged.0.lines.0.qty', 2);
        $this->assertSame($before, $this->rows());
        $this->finalize($device, $table, $this->intent($device, $table, $input))->assertOk();
        $this->assertSame(2, QrOrderRound::count());
        $this->assertSame(2, $order->items()->count());
    }

    public function test_original_proof_endpoint_remains_non_authorizing_before_and_after_baseline(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture('handheld', true);
        $url = '/api/v1/device/tables/'.$table->id.'/draft-proof?'.http_build_query($input);
        $this->withToken($device->plainTextToken)->getJson($url)->assertOk()
            ->assertJsonPath('data.delta_policy', 'blocked_until_baseline_adoption')->assertJsonPath('data.archive_authorized', false);
        $this->finalize($device, $table, $this->intent($device, $table, $input))->assertOk();
        $before = $this->rows();
        $this->withToken($device->plainTextToken)->getJson($url)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_changed');
        $this->assertSame($before, $this->rows());
    }

    public function test_missing_one_own_round_refuses_but_another_device_round_is_not_local_evidence(): void
    {
        [$device, $table, $seat, $product, $first, $order, $input] = $this->fixture();
        $second = $this->roundEvent($device, $seat, $product->id, 3);
        $other = $this->seatingDevice('handheld');
        $this->roundEvent($other, $seat, $product->id, 4);
        $before = $this->rows();
        $this->preview($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_recovery_incomplete_history');
        $input['event_ids'][] = $second->client_event_id;
        $this->preview($device, $table, $input)->assertOk()->assertJsonCount(2, 'data.proof.acknowledged')
            ->assertJsonPath('data.proof.bill.grand_total_baisas', 9000);
        $this->assertSame($before, $this->rows());
    }

    public static function lateHistory(): array
    {
        return [['order.hold', 'processed'], ['order.hold', 'failed'], ['order.create', 'processed'], ['order.create', 'received']];
    }

    #[DataProvider('lateHistory')]
    public function test_extra_legacy_snapshot_history_is_never_silently_ignored(string $type, string $status): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture('handheld', true);
        $extra = $event->replicate();
        $extra->client_event_id = (string) Str::uuid();
        $extra->event_type = $type;
        $extra->ack_status = $status;
        $extra->save();
        $before = $this->rows();
        $this->preview($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_changed');
        $this->assertSame($before, $this->rows());
    }

    public static function changedEvidence(): array
    {
        return array_map(static fn (string $case): array => [$case], ['paid', 'billing', 'claim', 'uncertain', 'transfer',
            'joined', 'archived', 'extra_bill', 'round_pending', 'round_rejected', 'round_review', 'cancelled', 'held_line',
            'malformed_line', 'duplicate_owner', 'missing_event', 'failed_event', 'changed_ack', 'changed_item', 'fractional']);
    }

    #[DataProvider('changedEvidence')]
    public function test_changed_or_ambiguous_evidence_refuses_finalization_without_a_single_write(string $case): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture();
        $intent = $this->intent($device, $table, $input);
        $round = QrOrderRound::sole();
        switch ($case) {
            case 'paid': $order->update(['status' => 'paid']);
                break;
            case 'billing': $seat->update(['billing_at' => now()]);
                break;
            case 'claim': $order->update(['charge_device_id' => $device->id]);
                break;
            case 'uncertain': $order->update(['charge_outcome' => 'uncertain']);
                break;
            case 'transfer': $order->update(['transferred_from_device_id' => $device->id]);
                break;
            case 'joined': $this->seatingRow($this->seatingTable('T2'), ['merged_into_id' => $seat->id, 'order_id' => $order->id]);
                break;
            case 'archived': $table->delete();
                break;
            case 'extra_bill': $copy = $order->replicate();
                $copy->uuid = (string) Str::uuid();
                $copy->table_session_id = null;
                $copy->save();
                break;
            case 'round_pending': $round->update(['status' => 'pending_confirmation']);
                break;
            case 'round_rejected': $round->update(['status' => 'rejected']);
                break;
            case 'round_review': $round->update(['needs_review' => true]);
                break;
            case 'cancelled': $lines = $round->priced_lines;
                $lines[0]['cancellations'] = [['qty' => 1]];
                $round->update(['priced_lines' => $lines]);
                break;
            case 'held_line': $lines = $round->priced_lines;
                $lines[0]['held_reason'] = 'missing';
                $round->update(['priced_lines' => $lines]);
                break;
            case 'malformed_line': $round->update(['priced_lines' => ['not a line']]);
                break;
            case 'duplicate_owner': $this->seatingRound($seat, $order, ['round_no' => 2, 'priced_lines' => $round->priced_lines]);
                break;
            case 'missing_event': $event->delete();
                break;
            case 'failed_event': $event->update(['ack_status' => 'failed']);
                break;
            case 'changed_ack': $ack = $event->result_json;
                $ack['total_baisas']++;
                $event->update(['result_json' => $ack]);
                break;
            case 'changed_item': $order->items()->sole()->update(['notes' => 'Changed']);
                break;
            case 'fractional': $order->items()->sole()->update(['qty' => '1.500']);
                break;
        }
        $before = $this->rows();
        $response = $this->finalize($device, $table, $intent);
        $this->assertContains($response->status(), [404, 409]);
        $response->assertJsonMissingPath('draft_recovery_final_no_write');
        $this->assertSame($before, $this->rows());
    }

    public function test_new_valid_round_makes_the_signed_preview_stale_without_archiving(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture();
        $intent = $this->intent($device, $table, $input);
        $other = $this->seatingDevice('handheld');
        $this->roundEvent($other, $seat, $product->id, 3);
        $before = $this->rows();
        $this->finalize($device, $table, $intent)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_recovery_preview_stale')
            ->assertJsonMissingPath('draft_recovery_final_no_write');
        $this->assertSame($before, $this->rows());
    }

    public static function replayStates(): array
    {
        return [['staff', 'paid'], ['staff', 'claim'], ['legacy', 'paid'], ['legacy', 'closed'], ['legacy', 'archived']];
    }

    #[DataProvider('replayStates')]
    public function test_lost_reply_replays_original_receipt_after_expiry_and_payment_changes(string $kind, string $state): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture('handheld', $kind === 'legacy');
        $intent = $this->intent($device, $table, $input);
        $response = $this->finalize($device, $table, $intent)->assertOk();
        $this->travel(10)->minutes();
        if ($state === 'archived') {
            $table->delete();
        } elseif ($state === 'closed') {
            $seat->update(['status' => 'closed', 'closed_at' => now()]);
        } elseif ($state === 'claim') {
            $order->update(['charge_device_id' => $device->id, 'charge_amount_baisas' => 2000, 'charge_deadline_at' => now()->addMinutes(1)]);
        } else {
            $order->update(['status' => 'paid', 'closed_at' => now()]);
        }
        $before = $this->rows();
        $this->finalize($device, $table, $intent)->assertOk()->assertExactJson($response->json());
        $this->assertSame($before, $this->rows());
        $this->assertSame(1, TableSessionEvent::where('payload->action', 'same_bill_draft_recovered')->count());
    }

    public function test_unapplied_expired_intent_has_exact_final_no_write_proof_and_can_never_start_later(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture();
        $second = $this->roundEvent($device, $seat, $product->id);
        $input['event_ids'][] = $second->client_event_id;
        $intent = $this->intent($device, $table, $input);
        $this->travel(5)->minutes();
        $before = $this->rows();
        $eventIds = $intent['event_ids'];
        sort($eventIds, SORT_STRING);
        $normalized = ['order_uuid' => $intent['order_uuid'], 'kind' => $intent['kind'],
            'client_request_id' => $intent['client_request_id'], 'preview_token' => $intent['preview_token'],
            'local_snapshot_hash' => $intent['local_snapshot_hash'], 'event_ids' => $eventIds];
        $response = $this->finalize($device, $table, $intent)->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'draft_recovery_preview_stale')
            ->assertJsonPath('draft_recovery_final_no_write', $normalized + ['table_id' => $table->id]);
        $this->travel(1)->minute();
        $this->finalize($device, $table, $intent)->assertStatus(409)->assertExactJson($response->json());
        $this->assertSame($before, $this->rows());
    }

    public static function conflicts(): array
    {
        return [['local_snapshot_hash'], ['preview_token'], ['order_uuid'], ['event_ids'], ['kind']];
    }

    #[DataProvider('conflicts')]
    public function test_same_request_id_never_retargets_different_local_evidence(string $field): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture();
        $intent = $this->intent($device, $table, $input);
        $this->finalize($device, $table, $intent)->assertOk();
        $changed = $intent;
        if ($field === 'kind') {
            $changed['kind'] = 'legacy_hold';
            unset($changed['event_ids']);
        } else {
            $changed[$field] = match ($field) {
                'local_snapshot_hash' => hash('sha256', 'different local rows'),
                'preview_token' => '1.'.str_repeat('0', 64),
                'order_uuid' => (string) Str::uuid(),
                'event_ids' => [(string) Str::uuid()],
            };
        }
        $before = $this->rows();
        $this->finalize($device, $table, $changed)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_recovery_request_conflict');
        $this->assertSame($before, $this->rows());
    }

    public function test_token_forgery_and_foreign_device_cannot_authorize_archive(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture();
        $intent = $this->intent($device, $table, $input);
        $other = $this->seatingDevice('handheld');
        $foreign = $this->seatingDevice('handheld', 20, 200);
        $forged = $intent;
        $forged['preview_token'] = explode('.', $intent['preview_token'])[0].'.'.str_repeat('0', 64);
        $before = $this->rows();
        $this->finalize($device, $table, $forged)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_recovery_preview_stale');
        $this->finalize($other, $table, $intent)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_owner_required');
        $this->finalize($foreign, $table, $intent)->assertNotFound();
        $this->assertSame($before, $this->rows());
    }

    public function test_station_gate_precedes_lookup_and_validation_on_both_endpoints(): void
    {
        $station = $this->seatingDevice('payment_station');
        $table = new Table(['id' => 999]);
        $before = $this->rows();
        $this->preview($station, $table, [])->assertStatus(409)->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->finalize($station, $table, [])->assertStatus(409)->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->assertSame($before, $this->rows());
    }

    public function test_catalogue_changes_do_not_reprice_original_item_evidence(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture('handheld', true);
        $product->update(['name' => 'Changed catalogue label', 'base_price' => '99.000', 'status' => 'inactive']);
        $this->finalize($device, $table, $this->intent($device, $table, $input))->assertOk();
        $this->assertSame('2.000', (string) $order->fresh()->grand_total);
        $this->assertSame('Seating coffee', $order->items()->sole()->product_name_snapshot);
        $this->assertSame(2000, QrOrderRound::sole()->total_baisas);
    }

    public static function adoptedKinds(): array
    {
        return [['staff_rounds'], ['legacy_hold']];
    }

    private function customerSession(TableSession $seat): QrSession
    {
        $station = $this->seatingDevice('payment_station');

        return QrSession::create(['uuid' => (string) Str::uuid(), 'company_id' => $seat->company_id,
            'branch_id' => $seat->branch_id, 'device_id' => $station->id, 'table_id' => $seat->table_id,
            'table_session_id' => $seat->id, 'token' => hash('sha256', (string) Str::uuid()),
            'status' => QrSession::STATUS_ACTIVE, 'expires_at' => now()->addHours(6), 'token_expires_at' => now()->addHours(6),
            'bound_at' => now(), 'client_secret_hash' => QrSession::hashClientSecret('synthetic-recovery-secret')]);
    }

    #[DataProvider('adoptedKinds')]
    public function test_customer_adopted_bill_recovery_transcript_and_telemetry_stability(string $kind): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture('handheld', $kind === 'legacy_hold');
        $session = $this->customerSession($seat);
        app(SubmitDineInQrRoundAction::class)->handle($session->id, [
            'client_request_id' => 'customer-adoption', 'phone' => '99000000',
            'lines' => [['product_id' => $product->id, 'qty' => 3, 'notes' => null, 'addon_ids' => []]],
        ], '127.0.0.1');
        $this->assertSame(Order::SOURCE_QR_WEB, $order->fresh()->source);
        $preview = $this->preview($device, $table, $input)->assertOk()
            ->assertJsonPath('data.proof.bill.grand_total_baisas', 5000)
            ->assertJsonCount(1, 'data.proof.acknowledged')->assertJsonPath('data.proof.acknowledged.0.lines.0.qty', 2);
        $intent = $input + ['client_request_id' => (string) Str::uuid(), 'local_snapshot_hash' => hash('sha256', 'literal-'.$kind),
            'preview_token' => $preview->json('data.preview_token')];
        $this->travel(3)->seconds();
        $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => 'synthetic-recovery-secret'])
            ->getJson('/api/v1/public/qr/status')->assertOk();
        $this->assertSame(now()->timestamp, $session->fresh()->last_seen_at->timestamp);
        $this->app['auth']->forgetGuards();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/heartbeat',
            ['battery' => 80, 'app_version' => 'test-recovery', 'lat' => 0, 'lng' => 0])->assertOk();
        $before = $this->rows();
        $response = $this->finalize($device, $table, $intent)->assertOk();
        $after = $this->rows();
        unset($before['pos_table_session_events'], $after['pos_table_session_events']);
        $this->assertSame($before, $after);
        $tag = $kind === 'legacy_hold' ? 'LEGACY' : 'STAFF';
        fwrite(STDOUT, "\nUNIFIED_DRAFT_RECOVERY_{$tag}_PREVIEW=".json_encode($preview->json(), JSON_THROW_ON_ERROR)."\n");
        fwrite(STDOUT, "UNIFIED_DRAFT_RECOVERY_{$tag}_ACK=".json_encode($response->json(), JSON_THROW_ON_ERROR)."\n");
        $this->travel(6)->minutes();
        $this->finalize($device, $table, $intent)->assertOk()->assertExactJson($response->json());
        $intent['client_request_id'] = (string) Str::uuid();
        $noWrite = $this->finalize($device, $table, $intent)->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'draft_recovery_preview_stale');
        $this->assertSame($intent['client_request_id'], $noWrite->json('draft_recovery_final_no_write.client_request_id'));
        fwrite(STDOUT, "UNIFIED_DRAFT_RECOVERY_{$tag}_FINAL_NO_WRITE=".json_encode($noWrite->json(), JSON_THROW_ON_ERROR)."\n");
    }

    public function test_staff_detail_exposes_persisted_request_id_for_exact_delta_ack_verification(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture();
        $round = QrOrderRound::sole();
        $this->withToken($device->plainTextToken)->getJson('/api/v1/device/tables/'.$table->id.'/detail')->assertOk()
            ->assertJsonPath('data.rounds.0.id', $round->id)->assertJsonPath('data.rounds.0.round_no', 1)
            ->assertJsonPath('data.rounds.0.status', 'accepted')
            ->assertJsonPath('data.rounds.0.client_request_id', $event->payload_json['client_request_id']);
    }

    public static function foreignLineCorruption(): array
    {
        $cases = [];
        foreach (['other_device', 'customer'] as $origin) {
            foreach (['qty', 'name', 'addon', 'money', 'orphan_id'] as $field) {
                $cases[$origin.' '.$field] = [$origin, $field];
            }
        }

        return $cases;
    }

    #[DataProvider('foreignLineCorruption')]
    public function test_balanced_totals_cannot_hide_another_rounds_corrupt_item_identity(string $origin, string $field): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture();
        if ($origin === 'customer') {
            $session = $this->customerSession($seat);
            app(SubmitDineInQrRoundAction::class)->handle($session->id, [
                'client_request_id' => 'customer-adoption', 'phone' => '99000000',
                'lines' => [['product_id' => $product->id, 'qty' => 3, 'notes' => null, 'addon_ids' => []]],
            ], '127.0.0.1');
            $round = QrOrderRound::where('qr_session_id', $session->id)->sole();
        } else {
            $other = $this->seatingDevice('handheld');
            $ack = $this->roundEvent($other, $seat, $product->id, 3);
            $round = QrOrderRound::findOrFail($ack->result_json['round_id']);
        }
        $intent = $this->intent($device, $table, $input);
        $lines = $round->priced_lines;
        switch ($field) {
            case 'qty': $lines[0]['qty'] = 1;
                $lines[0]['unit_price_baisas'] = 3000;
                break;
            case 'name': $lines[0]['product_name'] = 'Wrong original name';
                break;
            case 'addon': $lines[0]['addons'] = [['add_on_id' => 123, 'name' => 'Unrecorded', 'price_delta_baisas' => 0]];
                break;
            case 'money': $lines[0]['unit_price_baisas'] = 999;
                break;
            case 'orphan_id': $lines[0]['order_item_id'] = 99999;
                break;
        }
        $round->update(['priced_lines' => $lines]);
        $this->assertSame(5000, (int) QrOrderRound::sum('total_baisas'));
        $this->assertSame('5.000', (string) $order->fresh()->grand_total);
        $before = $this->rows();
        $this->preview($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_changed');
        $this->finalize($device, $table, $intent)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_changed');
        $this->assertSame($before, $this->rows());
    }

    public function test_complete_attached_alias_ack_is_proven_but_cannot_be_omitted(): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture();
        $alias = $this->seatingRow($table, ['status' => TableSession::STATUS_MERGED, 'merged_into_id' => $seat->id,
            'close_reason' => TableSession::CLOSE_ATTACHED, 'closed_at' => now(), 'opened_by_device_id' => $device->id]);
        $second = $this->roundEvent($device, $alias, $product->id, 3);
        $this->assertSame($alias->uuid, $second->result_json['table_session_uuid']);
        $this->assertSame($seat->uuid, $second->result_json['winner_table_session_uuid']);
        $before = $this->rows();
        $this->preview($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_recovery_incomplete_history');
        $input['event_ids'][] = $second->client_event_id;
        $proof = $this->preview($device, $table, $input)->assertOk()->assertJsonCount(2, 'data.proof.acknowledged');
        $this->assertContains($alias->client_request_id, array_column($proof->json('data.proof.acknowledged'), 'seating_key'));
        $this->assertSame($before, $this->rows());
        $this->finalize($device, $table, $this->intent($device, $table, $input))->assertOk();
        $this->assertSame(2, QrOrderRound::count());
    }

    public static function unresolvedAliasStatuses(): array
    {
        return [['received'], ['failed'], ['processed']];
    }

    #[DataProvider('unresolvedAliasStatuses')]
    public function test_unresolved_own_alias_attempt_without_bill_result_cannot_be_ignored(string $status): void
    {
        [$device, $table, $seat, $product, $event, $order, $input] = $this->fixture();
        $intent = $this->intent($device, $table, $input);
        $alias = $this->seatingRow($table, ['status' => TableSession::STATUS_MERGED, 'merged_into_id' => $seat->id,
            'close_reason' => TableSession::CLOSE_ATTACHED, 'closed_at' => now(), 'opened_by_device_id' => $device->id]);
        $extra = $event->replicate();
        $extra->client_event_id = (string) Str::uuid();
        $payload = $extra->payload_json;
        $payload['seating_key'] = $alias->client_request_id;
        $payload['client_request_id'] = (string) Str::uuid();
        $extra->payload_json = $payload;
        $extra->result_json = [];
        $extra->ack_status = $status;
        $extra->save();
        $before = $this->rows();
        $this->preview($device, $table, $input)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_changed');
        $this->finalize($device, $table, $intent)->assertStatus(409)->assertJsonPath('errors.0.code', 'draft_proof_evidence_changed');
        $this->assertSame($before, $this->rows());
    }

    public function test_b13_heartbeat_between_preview_and_apply_does_not_change_signature(): void
    {
        [$device,$table,$seat,$product,$event,$order,$input] = $this->fixture();
        $intent = $this->intent($device, $table, $input);
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/heartbeat', [
            'pending_outbox_count' => 3, 'quarantined_count' => 2, 'printer_status' => 'ready'])->assertOk();
        $this->finalize($device, $table, $intent)->assertOk()->assertJsonPath('data.result.outcome', 'draft_recovered');
    }

    public function test_fix1_legacy_hold_proof_is_scoped_to_originating_device(): void
    {
        [$device,$table,$seat,$product,$event,$order,$input] = $this->fixture('handheld', true);
        $other = $this->seatingDevice();
        $foreign = $event->replicate();
        $foreign->forceFill(['id' => 0, 'device_id' => $other->id, 'payload_json' => ['order' => ['uuid' => 'not-this-order']]])->save();
        $intent = $this->intent($device, $table, $input);
        $this->finalize($device, $table, $intent)->assertOk()->assertJsonPath('data.result.outcome','draft_recovered');
    }
}
