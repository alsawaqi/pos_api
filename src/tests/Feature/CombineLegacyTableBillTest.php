<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\ClaimQrSettlementAction;
use App\Actions\Qr\ListAcceptedDineInQrRoundsAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\CancelStaffLineAction;
use App\Actions\Tables\CombineLegacyTableBillAction;
use App\Actions\Tables\FrozenLegacyTableBill;
use App\Actions\Tables\ReadTableDetailAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class CombineLegacyTableBillTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    private Device $device;

    private Order $source;

    private Order $target;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now()->startOfSecond());
        $this->device = $this->seatingDevice();
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $this->productId = $product->id;
        app(OpenDineInTableAction::class)->handle($station, $table->id);
        app(BindQrTableSessionAction::class)->handle($table->qr_token, 'combine-owner-secret');
        $credential = QrSession::query()->where('table_id', $table->id)->latest('id')->firstOrFail();
        $seat = $credential->tableSession;
        app(AppendStaffRoundAction::class)->handle($this->device, [
            'seating_key' => (string) Str::uuid(), 'table_id' => $table->id, 'queued_offline' => false,
            'client_request_id' => 'existing-staff', 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $product->id, 'qty' => 1]],
        ], now(), now(), $seat->uuid);
        $this->target = Order::findOrFail($seat->fresh()->order_id);
        // Match the identity that customer adoption writes; money/rounds come
        // from the real staff writer, not a hand-invented target total.
        $this->target->update(['source' => Order::SOURCE_QR_WEB, 'qr_session_id' => $credential->id]);
        $this->source = Order::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $this->device->id,
            'table_id' => $table->id, 'order_type' => 'dine_in', 'source' => 'main_pos', 'status' => 'held',
            'subtotal' => '2.468', 'discount_total' => '0.168', 'comp_total' => '0.000', 'tax_total' => '0.115',
            'grand_total' => '2.415', 'opened_at' => now()->subMinutes(20), 'temp_reference' => 'OLD-007',
            'note' => 'Original staff note',
        ]);
        $item = DB::table('pos_order_items')->insertGetId([
            'order_id' => $this->source->id, 'product_id' => $product->id, 'product_name_snapshot' => 'Frozen coffee',
            'qty' => '2.000', 'unit_price_snapshot' => '1.234', 'line_discount' => '0.068', 'line_total' => '2.468',
            'recipe_snapshot_json' => '[]', 'component_snapshot_json' => '[]', 'notes' => 'no sugar',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_order_item_addons')->insert([
            'order_item_id' => $item, 'add_on_id' => null, 'add_on_name_snapshot' => 'Frozen extra',
            'price_delta_snapshot' => '0.234', 'consumption_snapshot_json' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$item, '0.068'], [null, '0.100']] as [$itemId, $amount]) {
            DB::table('pos_order_discounts')->insert([
                'company_id' => 100, 'branch_id' => 10, 'order_id' => $this->source->id,
                'order_item_id' => $itemId, 'name_snapshot' => 'Frozen discount', 'amount' => $amount,
                'applied_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('pos_staff')->insert([
            'id' => 700, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Test manager', 'pin_hash' => Hash::make('4321'), 'position' => 'manager', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function input(): array
    {
        $preview = app(CombineLegacyTableBillAction::class)->preview($this->device, $this->source->table_id, $this->source->uuid);

        return ['source_order_uuid' => $this->source->uuid, 'target_order_uuid' => $this->target->uuid,
            'client_request_id' => (string) Str::uuid(), 'preview_token' => $preview['preview_token'],
            'reason' => 'same_party_duplicate_bill', 'pin' => '4321'];
    }

    private function combine(?array $input = null): array
    {
        return app(CombineLegacyTableBillAction::class)->handle($this->device, $this->source->table_id, $input ?? $this->input());
    }

    private function rows(): array
    {
        $snapshot = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'pos_%' ORDER BY name") as $table) {
            if (in_array($table->name, ['pos_devices', 'pos_sync_events'], true)) {
                continue;
            }
            $snapshot[$table->name] = DB::table($table->name)->get()->map(fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
        }

        return $snapshot;
    }

    private function refused(callable $operation, string $code): void
    {
        $before = $this->rows();
        try {
            $operation();
            $this->fail('The combine must refuse.');
        } catch (QrDineInException $exception) {
            $this->assertSame($code, $exception->codeName);
        }
        $this->assertSame($before, $this->rows(), 'A refusal changes no business row.');
    }

    public function test_preview_is_private_read_only_and_does_not_expose_identity_or_snapshots(): void
    {
        $before = $this->rows();
        $response = $this->withToken($this->device->device_token)->getJson('/api/v1/device/tables/'.$this->source->table_id.'/combine-preview?source_order_uuid='.$this->source->uuid)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.source.grand_total_baisas', 2415)->assertJsonPath('data.target.grand_total_baisas', 1000)
            ->assertJsonPath('data.combined_grand_total_baisas', 3415)->assertJsonPath('data.requires_manager_pin', true);
        foreach (['pin_hash', 'client_secret', 'customer_id', 'recipe_snapshot', 'component_snapshot', 'plate_number'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_combine_preserves_frozen_children_reference_and_audit_without_payment_stock_or_auto_print(): void
    {
        $frozen = app(FrozenLegacyTableBill::class);
        $sourceBefore = $frozen->snapshot($this->source->fresh());
        $targetBefore = $frozen->snapshot($this->target->fresh());
        $feedBefore = app(ListAcceptedDineInQrRoundsAction::class)->handle($this->device);
        $before = $this->rows();
        $input = $this->input();
        $result = $this->combine($input);
        $this->assertSame('combined', $result['outcome']);
        $this->assertSame(3415, $result['grand_total_baisas']);
        $this->assertSame($this->target->uuid, $result['order_uuid']);
        $this->assertSame($this->target->temp_reference, $result['temp_reference']);
        $this->assertGreaterThan(0, $result['event_id']);
        $this->assertSame(700, $result['approved_by_staff_id']);
        $sourceAfter = $frozen->snapshot($this->source->fresh());
        $this->assertSame('combined', $sourceAfter['order']['status']);
        $this->assertSame(Arr::except($sourceBefore['order'], ['status', 'closed_at', 'updated_at']),
            Arr::except($sourceAfter['order'], ['status', 'closed_at', 'updated_at']));
        $this->assertSame(Arr::except($sourceBefore, ['order']), Arr::except($sourceAfter, ['order']));
        $targetAfter = $frozen->snapshot($this->target->fresh());
        $this->assertSame(Arr::except($targetBefore['order'], ['subtotal', 'discount_total', 'tax_total', 'grand_total', 'updated_at']),
            Arr::except($targetAfter['order'], ['subtotal', 'discount_total', 'tax_total', 'grand_total', 'updated_at']));
        $event = TableSessionEvent::findOrFail($result['event_id']);
        foreach ($event->payload['item_id_map'] as $oldId => $newId) {
            $old = (array) DB::table('pos_order_items')->find($oldId);
            $new = (array) DB::table('pos_order_items')->find($newId);
            $this->assertSame(Arr::except($old, ['id', 'order_id']), Arr::except($new, ['id', 'order_id']));
            $this->assertSame($this->target->id, $new['order_id']);
        }
        $imported = QrOrderRound::findOrFail($result['round_id']);
        $this->assertNull($imported->accepted_seq);
        $this->assertNull($imported->kitchen_printed_at);
        $this->assertNull($imported->confirm_payload);
        $this->assertSame([1, 2], QrOrderRound::where('order_id', $this->target->id)->orderBy('round_no')->pluck('round_no')->all());
        $feedAfter = app(ListAcceptedDineInQrRoundsAction::class)->handle($this->device);
        // Ciphertexts have random IVs; compare every field including the exact
        // decrypted cursor payload, not the encryption randomness.
        foreach (['next_cursor', 'latest_cursor'] as $cursor) {
            $feedBefore[$cursor] = Crypt::decryptString($feedBefore[$cursor]);
            $feedAfter[$cursor] = Crypt::decryptString($feedAfter[$cursor]);
        }
        $this->assertSame($feedBefore, $feedAfter);
        $detail = app(ReadTableDetailAction::class)->handle($this->device, $this->source->table_id);
        $this->assertSame($this->target->uuid, $detail['bill']['uuid']);
        $this->assertCount(2, $detail['rounds']);
        $allowed = ['pos_orders', 'pos_order_items', 'pos_order_item_addons', 'pos_order_discounts', 'pos_qr_order_rounds', 'pos_table_session_events'];
        $this->assertSame(Arr::except($before, $allowed), Arr::except($this->rows(), $allowed));
        $this->assertStringNotContainsString('"pin":', json_encode($event->payload));
        $this->assertStringNotContainsString('pin_hash', json_encode($event->payload));
    }

    public function test_exact_retry_after_lost_reply_is_stable_even_after_the_target_gets_another_round(): void
    {
        $input = $this->input();
        $first = $this->combine($input);
        $this->append();
        $before = $this->rows();
        $this->travel(10)->minutes();
        $this->assertSame($first, $this->combine($input));
        $this->assertSame($before, $this->rows());
        $this->refused(fn () => $this->combine(array_replace($input, ['preview_token' => 'different'])), 'combine_request_conflict');
    }

    public function test_imported_round_survives_future_append_and_frozen_cancellation_to_the_baisa(): void
    {
        $this->combine();
        $this->append();
        $this->assertSame('4.415', $this->target->fresh()->grand_total);
        // Match only the imported line, not the pre-existing/new plain coffee.
        $result = app(CancelStaffLineAction::class)->handle($this->device, [
            'seating_key' => (string) Str::uuid(), 'table_id' => $this->source->table_id, 'queued_offline' => false,
            'client_request_id' => 'cancel-imported', 'product_id' => $this->productId,
            'addon_ids' => [0], 'notes' => 'no sugar', 'qty' => 1, 'prepared' => false,
            'cancelled_at' => now()->toIso8601String(),
        ], now(), now(), $this->target->tableSession->uuid);
        $this->assertSame('cancelled', $result['outcome']);
        $this->assertSame(1, $result['cancelled_qty']);
        $this->assertSame(3208, $result['grand_total_baisas']);
        $this->assertSame('2.415', $this->source->fresh()->grand_total);
    }

    private function append(): void
    {
        app(AppendStaffRoundAction::class)->handle($this->device, [
            'seating_key' => (string) Str::uuid(), 'table_id' => $this->source->table_id, 'queued_offline' => false,
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $this->productId, 'qty' => 1]],
        ], now(), now(), $this->target->tableSession->uuid);
    }

    public static function changes(): array
    {
        return ['source changed' => ['source'], 'target round' => ['round'], 'price snapshot' => ['item'],
            'expired preview' => ['expiry'], 'tampered signature' => ['signature']];
    }

    #[DataProvider('changes')]
    public function test_changed_or_expired_preview_refuses_without_writes(string $change): void
    {
        $input = $this->input();
        match ($change) {
            'source' => $this->source->update(['note' => 'changed']),
            'round' => $this->append(),
            'item' => DB::table('pos_order_items')->where('order_id', $this->source->id)->update(['notes' => 'different']),
            'expiry' => $this->travel(6)->minutes(),
            'signature' => $input['preview_token'] .= 'x',
        };
        $this->refused(fn () => $this->combine($input), 'combine_preview_stale');
    }

    public static function provenance(): iterable
    {
        foreach (['source', 'target'] as $bill) {
            foreach (['charge_device_id' => 1, 'charge_amount_baisas' => 10, 'charge_roundup_amount_baisas' => 1,
                'charge_claimed_at' => '2026-09-12 10:00:00', 'charge_deadline_at' => '2026-09-12 10:05:00',
                'charge_outcome' => 'declined', 'transferred_to_device_id' => 1] as $field => $value) {
                yield "$bill/$field" => [$bill, $field, $value];
            }
        }
    }

    #[DataProvider('provenance')]
    public function test_any_payment_or_transfer_provenance_refuses_without_writes(string $bill, string $field, mixed $value): void
    {
        $input = $this->input();
        $this->$bill->update([$field => $value]);
        $this->refused(fn () => $this->combine($input), 'combine_payment_or_transfer');
    }

    public function test_manager_pin_is_required_and_a_non_manager_cannot_approve(): void
    {
        $input = $this->input();
        $this->refused(fn () => $this->combine(array_replace($input, ['pin' => '1111'])), 'invalid_pin');
        DB::table('pos_staff')->where('id', 700)->update(['position' => 'cashier']);
        $this->refused(fn () => $this->combine($input), 'invalid_pin');
    }

    public function test_company_manager_policy_is_honoured_without_client_authorization_claims(): void
    {
        DB::table('pos_staff')->where('id', 700)->update(['position' => 'supervisor']);
        DB::table('pos_company_settings')->insert(['company_id' => 100, 'key' => 'manager_approval_positions', 'value' => '["supervisor"]']);
        $this->assertSame(700, $this->combine()['approved_by_staff_id']);
    }

    public static function unsafeBills(): array
    {
        return ['comp' => ['comp'], 'fractional quantity' => ['fraction'], 'wrong frozen subtotal' => ['money'],
            'missing discount attribution' => ['discount'], 'joined coverage' => ['joined'], 'third bill' => ['third'],
            'paid source' => ['paid'], 'wrong branch' => ['branch'], 'source shared history' => ['history']];
    }

    #[DataProvider('unsafeBills')]
    public function test_unsupported_or_conflicting_bill_is_not_silently_repaired(string $case): void
    {
        $input = $this->input();
        $code = 'combine_accounting_unsupported';
        switch ($case) {
            case 'comp': $this->source->update(['comp_total' => '0.001']);
                break;
            case 'fraction': DB::table('pos_order_items')->where('order_id', $this->source->id)->update(['qty' => '1.500']);
                break;
            case 'money': $this->source->update(['subtotal' => '2.000']);
                break;
            case 'discount': DB::table('pos_order_discounts')->where('order_id', $this->source->id)->delete();
                break;
            case 'joined':
                DB::table('pos_order_tables')->insert(['order_id' => $this->source->id, 'table_id' => $this->seatingTable('T2')->id]);
                $code = 'combine_coverage_conflict';
                break;
            case 'third':
                $third = $this->source->replicate();
                $third->uuid = (string) Str::uuid();
                $third->save();
                $code = 'combine_coverage_conflict';
                break;
            case 'paid': $this->source->update(['status' => 'paid']);
                $code = 'combine_bill_ineligible';
                break;
            case 'branch': $this->source->update(['branch_id' => 20]);
                $code = 'order_not_found';
                break;
            case 'history':
                $this->seatingRow($this->seatingTable('old'), ['status' => 'closed', 'order_id' => $this->source->id]);
                $code = 'combine_bill_ineligible';
                break;
        }
        $this->refused(fn () => $this->combine($input), $code);
    }

    public static function oldWriters(): array
    {
        return ['hold' => ['order.hold'], 'create' => ['order.create'], 'transfer' => ['order.transfer'],
            'pay' => ['order.pay'], 'void' => ['order.void']];
    }

    #[DataProvider('oldWriters')]
    public function test_archived_source_cannot_be_replaced_paid_transferred_or_voided(string $type): void
    {
        $this->combine();
        $other = $this->seatingDevice('handheld');
        $before = $this->rows();
        $payload = ['order_uuid' => $this->source->uuid,
            'payments' => [['method' => 'cash', 'amount_baisas' => 2415]],
            'target_device_id' => $other->id,
            'order' => ['uuid' => $this->source->uuid, 'order_type' => 'dine_in', 'source' => 'main_pos',
                'table_id' => $this->source->table_id, 'opened_at' => now()->toIso8601String(),
                'subtotal_baisas' => 1000, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => 1000,
                'lines' => [['product_id' => $this->productId, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]]]];
        $error = match ($type) {
            'order.pay' => 'combined order is not payable: '.$this->source->uuid,
            'order.void' => 'combined order is read-only history: '.$this->source->uuid,
            default => 'order '.$this->source->uuid.' already exists in terminal status combined',
        };
        $this->withToken($this->device->device_token)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => $type,
            'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.error', $error);
        $this->assertSame($before, $this->rows());
        $this->assertSame('combined', $this->source->fresh()->status);
    }

    public function test_http_confirm_and_retry_return_same_processed_ack_and_manager_route_is_throttled(): void
    {
        $input = $this->input();
        $path = '/api/v1/device/tables/'.$this->source->table_id.'/combine';
        $first = $this->withToken($this->device->device_token)->postJson($path, $input)
            ->assertOk()->assertJsonPath('data.status', 'processed')->assertJsonPath('data.result.outcome', 'combined');
        fwrite(STDOUT, "\nUNIFIED_COMBINE_RESULT_JSON=".json_encode($first->json(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $this->withToken($this->device->device_token)->postJson($path, $input)->assertOk()->assertExactJson($first->json());
        $route = app('router')->getRoutes()->getByName('device.tables.combine');
        $this->assertContains('throttle:pos-login', $route->gatherMiddleware());
    }

    public function test_a_failed_journal_write_rolls_back_every_copy_and_the_source_status_then_allows_exact_retry(): void
    {
        $input = $this->input();
        $before = $this->rows();
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'insert into "pos_table_session_events"')) {
                $armed = false;
                throw new RuntimeException('journal unavailable');
            }
        });
        try {
            $this->combine($input);
            $this->fail('A journal failure must abort the combine.');
        } catch (RuntimeException $exception) {
            $this->assertSame('journal unavailable', $exception->getMessage());
        }
        $this->assertFalse($armed, 'The fault was injected after the domain writes.');
        $this->assertSame($before, $this->rows());
        $this->assertSame('combined', $this->combine($input)['outcome']);
    }

    public static function deviceCases(): array
    {
        return ['station' => ['payment_station', 10, 100], 'other branch' => ['fixed_pos', 20, 100],
            'other tenant' => ['handheld', 30, 200]];
    }

    #[DataProvider('deviceCases')]
    public function test_device_scope_is_checked_before_bill_disclosure(string $type, int $branchId, int $companyId): void
    {
        $device = $this->seatingDevice($type, $branchId, $companyId);
        $this->refused(fn () => app(CombineLegacyTableBillAction::class)->preview($device, $this->source->table_id, $this->source->uuid),
            $type === 'payment_station' ? 'device_not_attended' : 'table_not_found');
    }

    public function test_other_device_cannot_preview_or_confirm_but_handheld_can_combine_its_own_bill(): void
    {
        $input = $this->input();
        $this->device = $this->seatingDevice('handheld');
        $this->refused(fn () => $this->combine($input), 'combine_source_device_required');
        $this->refused(fn () => $this->input(), 'combine_source_device_required');
        $this->source->update(['device_id' => $this->device->id, 'source' => 'handheld']);
        $this->refused(fn () => $this->combine($input), 'combine_preview_stale');
        $this->assertSame('combined', $this->combine()['outcome']);
    }

    public static function ownershipChanges(): array
    {
        return ['new owner' => ['device_id', 999, 'combine_source_device_required'],
            'lost owner' => ['device_id', null, 'combine_source_device_required'],
            'transfer source history' => ['transferred_from_device_id', 999, 'combine_payment_or_transfer'],
            'transfer timestamp history' => ['transferred_at', '2026-09-12 10:00:00', 'combine_payment_or_transfer']];
    }

    #[DataProvider('ownershipChanges')]
    public function test_ownership_and_transfer_history_are_rechecked_without_writes(string $field, mixed $value, string $code): void
    {
        $input = $this->input();
        $this->source->update([$field => $value]);
        $this->refused(fn () => $this->combine($input), $code);
        $this->refused(fn () => $this->input(), $code);
    }

    public function test_expired_unapplied_intent_returns_exact_release_proof_but_committed_intent_replays(): void
    {
        $input = $this->input();
        $path = '/api/v1/device/tables/'.$this->source->table_id.'/combine';
        $this->travel(6)->minutes();
        $before = $this->rows();
        $this->withToken($this->device->device_token)->postJson($path, $input)->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'combine_preview_stale')
            ->assertJsonPath('combine_final_no_write', Arr::except($input, ['pin']) + ['table_id' => $this->source->table_id]);
        $this->assertSame($before, $this->rows());
        $input = $this->input();
        $result = $this->combine($input);
        $this->travel(6)->minutes();
        $this->withToken($this->device->device_token)->postJson($path, $input)->assertOk()
            ->assertJsonPath('data.result', $result)->assertJsonMissingPath('combine_final_no_write');
    }

    public function test_invalid_pin_or_unexpired_stale_preview_never_releases_a_pending_intent(): void
    {
        $input = $this->input();
        $path = '/api/v1/device/tables/'.$this->source->table_id.'/combine';
        $this->source->update(['note' => 'Changed']);
        $this->withToken($this->device->device_token)->postJson($path, $input)->assertStatus(409)
            ->assertJsonMissingPath('combine_final_no_write');
        $this->travel(6)->minutes();
        $this->withToken($this->device->device_token)->postJson($path, array_replace($input, ['pin' => '1111']))
            ->assertStatus(401)->assertJsonMissingPath('combine_final_no_write');
    }

    public function test_wrong_target_and_expired_or_inactive_manager_are_refused(): void
    {
        $input = $this->input();
        $this->refused(fn () => $this->combine(array_replace($input, ['target_order_uuid' => (string) Str::uuid()])), 'combine_preview_stale');
        DB::table('pos_staff')->where('id', 700)->update(['status' => 'inactive']);
        $this->refused(fn () => $this->combine($input), 'invalid_pin');
    }

    public static function paymentRows(): array
    {
        return ['source' => ['source'], 'target' => ['target']];
    }

    #[DataProvider('paymentRows')]
    public function test_payment_row_even_with_empty_claim_columns_is_a_no_write_refusal(string $bill): void
    {
        $input = $this->input();
        DB::table('pos_payments')->insert(['uuid' => (string) Str::uuid(), 'order_id' => $this->$bill->id,
            'method' => 'cash', 'amount' => '0.001', 'status' => 'failed']);
        $this->refused(fn () => $this->combine($input), 'combine_payment_or_transfer');
    }

    public function test_stock_evidence_prevents_importing_an_already_consumed_bill(): void
    {
        $input = $this->input();
        DB::table('pos_product_stock_movements')->insert(['company_id' => 100, 'branch_id' => 10,
            'product_id' => $this->productId, 'movement_type' => 'sale_consumption', 'quantity' => '-2.000',
            'reference_type' => 'pos_orders', 'reference_id' => $this->source->id]);
        $this->refused(fn () => $this->combine($input), 'combine_accounting_evidence');
    }

    public function test_claim_after_combine_freezes_the_sum_and_existing_payment_path_pays_only_the_survivor(): void
    {
        DB::table('pos_products')->where('id', $this->productId)->update(['stock_mode' => 'unit', 'base_price' => '99.000']);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $this->productId,
            'is_available' => true, 'stock_qty' => '10.000']);
        $catalogueReads = [];
        DB::listen(function ($query) use (&$catalogueReads): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"pos_products"')) {
                $catalogueReads[] = $query->sql;
            }
        });
        $this->combine();
        $this->assertSame([], $catalogueReads, 'Combining must never reprice from the current catalogue.');
        $this->assertEquals(10, DB::table('pos_branch_product')->where('product_id', $this->productId)->value('stock_qty'));
        $this->assertSame(0, DB::table('pos_product_stock_movements')->count());
        $claim = app(ClaimQrSettlementAction::class)->handle($this->device, ['order_uuid' => $this->target->uuid]);
        $this->assertSame(3415, $claim['charge_amount_baisas']);
        $replay = app(ClaimQrSettlementAction::class)->handle($this->device, ['order_uuid' => $this->target->uuid]);
        $this->assertTrue($replay['already_claimed_by_this_device']);
        $this->assertSame(3415, $replay['charge_amount_baisas']);
        $this->withToken($this->device->device_token)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $this->target->uuid, 'payments' => [['method' => 'cash', 'amount_baisas' => 3415]]],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame('paid', $this->target->fresh()->status);
        $this->assertSame('combined', $this->source->fresh()->status);
        $this->assertSame(1, DB::table('pos_payments')->where('order_id', $this->target->id)->count());
        $this->assertSame(0, DB::table('pos_payments')->where('order_id', $this->source->id)->count());
        $this->assertEquals(7, DB::table('pos_branch_product')->where('product_id', $this->productId)->value('stock_qty'));
        $this->assertEquals(-3, DB::table('pos_product_stock_movements')->where('reference_id', $this->target->id)->sum('quantity'));
        $this->assertSame(0, DB::table('pos_product_stock_movements')->where('reference_id', $this->source->id)->count());
    }

    public function test_a_void_line_with_remaining_quantity_is_not_imported_as_chargeable_stock(): void
    {
        $input = $this->input();
        DB::table('pos_order_items')->where('order_id', $this->source->id)->update(['status' => 'void']);
        $this->refused(fn () => $this->combine($input), 'combine_accounting_unsupported');
    }

    public function test_claim_between_preview_and_confirmation_refuses_without_changing_either_bill(): void
    {
        $input = $this->input();
        app(ClaimQrSettlementAction::class)->handle($this->device, ['order_uuid' => $this->target->uuid]);
        $this->refused(fn () => $this->combine($input), 'combine_table_not_open');
    }
}
