<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\ReadTableDetailAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Database\Events\QueryExecuted;
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

final class TableDetailTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-12 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function detail(Device $device, Table $table): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->device_token)->getJson('/api/v1/device/tables/'.$table->id.'/detail');
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'pos_%' ORDER BY name") as $table) {
            $rows = DB::table($table->name)->get()->map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows);
            $result[$table->name] = $rows;
        }

        return $result;
    }

    public static function readers(): array
    {
        return ['till' => ['fixed_pos'], 'handheld' => ['handheld']];
    }

    #[DataProvider('readers')]
    public function test_empty_table_has_literal_read_only_contract(string $type): void
    {
        $device = $this->seatingDevice($type);
        $table = $this->seatingTable('T1');
        $before = $this->snapshot();
        $response = $this->detail($device, $table)->assertOk()->assertExactJson([
            'data' => [
                'table' => ['id' => $table->id, 'label' => 'T1', 'floor_id' => $table->floor_id, 'status' => 'active', 'archived' => false],
                'occupied' => false, 'orphaned' => false, 'seating' => null, 'credential' => null, 'bill' => null, 'rounds' => [],
            ],
            'meta' => ['money_unit' => 'baisas', 'generated_at' => '2026-09-12T12:00:00+00:00'], 'errors' => [],
        ]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->snapshot());
        $this->assertContains('throttle:qr-table-device-read', Route::getRoutes()->getByName('device.tables.detail')->gatherMiddleware());
    }

    public static function sources(): iterable
    {
        foreach (['fixed_pos', 'handheld'] as $type) {
            foreach (['main_pos', 'handheld', 'qr_web'] as $source) {
                yield "$type/$source" => [$type, $source];
            }
        }
    }

    #[DataProvider('sources')]
    public function test_staff_and_customer_bills_are_displayed_without_repricing_or_identity_change(string $type, string $source): void
    {
        $device = $this->seatingDevice($type);
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table);
        $order = $this->seatingOrder($seat, ['source' => $source, 'subtotal' => '4.001', 'discount_total' => '0.101', 'tax_total' => '0.195', 'grand_total' => '4.095', 'plate_number' => 'PRIVATE-PLATE']);
        $round = $this->seatingRound($seat, $order);
        $before = $this->snapshot();
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.occupied', true)
            ->assertJsonPath('data.bill.uuid', $order->uuid)->assertJsonPath('data.bill.source', $source)
            ->assertJsonPath('data.bill.grand_total_baisas', 4095)->assertJsonPath('data.bill.subtotal_baisas', 4001)
            ->assertJsonPath('data.bill.discount_total_baisas', 101)->assertJsonPath('data.bill.tax_total_baisas', 195)
            ->assertJsonPath('data.bill.temp_reference', $seat->temp_reference)->assertJsonPath('data.rounds.0.id', $round->id)
            ->assertJsonMissingPath('data.bill.plate_number')->assertJsonMissingPath('data.bill.customer_id');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_joined_table_resolves_primary_bill_even_when_primary_table_and_floor_are_archived(): void
    {
        $device = $this->seatingDevice();
        $primaryTable = $this->seatingTable('T1');
        $primary = $this->seatingRow($primaryTable);
        $order = $this->seatingOrder($primary);
        $table = $this->seatingTable('T2');
        $joined = $this->seatingRow($table, ['merged_into_id' => $primary->id, 'order_id' => $order->id, 'temp_reference' => null]);
        $round = $this->seatingRound($primary, $order);
        $primaryTable->delete();
        DB::table('pos_floors')->where('id', $primaryTable->floor_id)->update(['deleted_at' => now()]);
        $before = $this->snapshot();
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.table.label', 'T2')
            ->assertJsonPath('data.seating.uuid', $primary->uuid)
            ->assertJsonPath('data.seating.selected_table_session_uuid', $joined->uuid)
            ->assertJsonPath('data.seating.table_id', $primaryTable->id)
            ->assertJsonPath('data.seating.joined_table_ids', [$table->id])
            ->assertJsonPath('data.bill.uuid', $order->uuid)->assertJsonPath('data.rounds.0.id', $round->id);
        $this->detail($device, $primaryTable)->assertOk()->assertJsonPath('data.table.archived', true)
            ->assertJsonPath('data.bill.uuid', $order->uuid);
        $this->assertSame($before, $this->snapshot());
    }

    public static function credentialStates(): iterable
    {
        foreach (['active', 'ordered', 'expired', 'released', 'missing'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('credentialStates')]
    public function test_round_history_is_bill_based_not_current_credential_based(string $state): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table, ['expires_at' => now()->subDay()]);
        $order = $this->seatingOrder($seat, ['source' => 'qr_web']);
        $old = $this->credential($seat, ['status' => 'closed', 'released_at' => now()]);
        $current = $this->credential($seat, ['status' => $state === 'released' ? 'closed' : ($state === 'missing' ? 'active' : $state),
            'released_at' => $state === 'released' ? now() : null, 'expires_at' => now()->subMinute(), 'handover_from_id' => $old->id]);
        $order->update(['qr_session_id' => $state === 'missing' ? null : $current->id]);
        $one = $this->seatingRound($seat, $order, ['qr_session_id' => $old->id, 'round_no' => 1]);
        $two = $this->seatingRound($seat, $order, ['round_no' => 1, 'status' => 'rejected']);
        $three = $this->seatingRound($seat, $order, ['qr_session_id' => $current->id, 'round_no' => 2, 'status' => 'pending_confirmation', 'resolved_at' => null, 'confirm_payload' => ['private' => 'never-return']]);
        $before = $this->snapshot();
        $response = $this->detail($device, $table)->assertOk()->assertJsonPath('data.occupied', true)->assertJsonCount(3, 'data.rounds');
        $this->assertSame([$one->id, $two->id, $three->id], array_column($response->json('data.rounds'), 'id'));
        $this->assertSame(['customer', 'staff', 'customer'], array_column($response->json('data.rounds'), 'entered_by'));
        $this->assertSame(['accepted', 'rejected', 'pending_confirmation'], array_column($response->json('data.rounds'), 'status'));
        $this->assertStringNotContainsString('never-return', $response->getContent());
        $this->assertSame($before, $this->snapshot(), 'Reading must never revive or expire a credential/seating.');
    }

    private function credential(TableSession $seat, array $attributes = []): QrSession
    {
        return QrSession::create(array_replace([
            'uuid' => (string) Str::uuid(), 'company_id' => $seat->company_id, 'branch_id' => $seat->branch_id,
            'device_id' => null, 'table_id' => $seat->table_id, 'table_session_id' => $seat->id, 'origin' => 'table_card',
            'status' => 'active', 'token' => Str::random(64), 'client_secret_hash' => hash('sha256', 'synthetic-secret'),
            'token_expires_at' => now()->addHour(), 'expires_at' => now()->addHour(),
        ], $attributes));
    }

    public static function arrivalOrders(): iterable
    {
        foreach (['fixed_pos', 'handheld'] as $type) {
            foreach ([true, false] as $staffFirst) {
                yield $type.($staffFirst ? '/staff-first' : '/customer-first') => [$type, $staffFirst];
            }
        }
    }

    #[DataProvider('arrivalOrders')]
    public function test_real_staff_customer_and_followup_rounds_share_one_displayed_bill(string $type, bool $staffFirst): void
    {
        $station = $this->seatingDevice('payment_station');
        $device = $this->seatingDevice($type);
        $table = $this->seatingTable('T1');
        $product = $this->seatingProduct();
        $open = app(OpenDineInTableAction::class)->handle($station, $table->id);
        $credential = app(BindQrTableSessionAction::class)->handle($open['table_token'], 'synthetic-client-secret');
        $this->assertInstanceOf(QrSession::class, $credential);
        $seat = TableSession::findOrFail($credential->table_session_id);
        $line = ['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null];
        $common = ['seating_key' => (string) Str::uuid(), 'table_id' => $table->id, 'queued_offline' => false];
        $staff = fn (string $id) => app(AppendStaffRoundAction::class)->handle($device, $common + [
            'client_request_id' => $id, 'submitted_at' => now()->toIso8601String(), 'lines' => [$line],
        ], now(), now(), $seat->uuid);
        $customer = fn () => app(SubmitDineInQrRoundAction::class)->handle($credential->id, [
            'client_request_id' => 'synthetic-customer-round', 'phone' => '99000000', 'lines' => [$line],
        ], '127.0.0.1');
        if ($staffFirst) {
            $first = $staff('first-staff-round');
            $this->detail($device, $table)->assertOk()->assertJsonPath('data.bill.uuid', $first['order_uuid'])
                ->assertJsonPath('data.rounds.0.entered_by', 'staff');
            $customer();
        } else {
            $first = $customer();
            $staff('first-staff-round');
        }
        $bill = Order::sole();
        $before = $this->snapshot();
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.bill.uuid', $bill->uuid)
            ->assertJsonPath('data.bill.grand_total_baisas', 2000)->assertJsonCount(2, 'data.bill.items');
        $this->assertSame($before, $this->snapshot());
        $last = $staff('followup-water-round');
        $this->assertSame($bill->uuid, $last['order_uuid']);
        $before = $this->snapshot();
        $response = $this->detail($device, $table)->assertOk()->assertJsonPath('data.bill.uuid', $bill->uuid)
            ->assertJsonPath('data.bill.temp_reference', $bill->temp_reference)
            ->assertJsonPath('data.bill.grand_total_baisas', 3000)->assertJsonCount(3, 'data.bill.items')->assertJsonCount(3, 'data.rounds');
        $this->assertSame([1, 2, 3], array_column($response->json('data.rounds'), 'round_no'));
        $this->assertSame($staffFirst ? ['staff', 'customer', 'staff'] : ['customer', 'staff', 'staff'], array_column($response->json('data.rounds'), 'entered_by'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, Order::count());
        if ($staffFirst && $type === 'fixed_pos') {
            fwrite(STDOUT, "\nUNIFIED_TABLE_DETAIL_JSON=".$response->getContent()."\n");
        }
    }

    public static function unpaidStatuses(): array
    {
        return array_combine(['open', 'held', 'kitchen', 'awaiting_payment'], array_map(static fn ($s) => [$s], ['open', 'held', 'kitchen', 'awaiting_payment']));
    }

    #[DataProvider('unpaidStatuses')]
    public function test_unpaid_orphan_is_still_occupied_without_inventing_a_live_seating(string $status): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table, ['status' => 'expired']);
        $order = $this->seatingOrder($seat, ['status' => $status]);
        $before = $this->snapshot();
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.occupied', true)
            ->assertJsonPath('data.orphaned', true)->assertJsonPath('data.seating.status', 'expired')
            ->assertJsonPath('data.bill.uuid', $order->uuid);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unseated_legacy_bill_and_pivot_are_visible_without_backfill(): void
    {
        $device = $this->seatingDevice();
        $primaryTable = $this->seatingTable();
        $seat = $this->seatingRow($primaryTable, ['status' => 'closed']);
        $order = $this->seatingOrder($seat, ['table_session_id' => null]);
        $table = $this->seatingTable('T2');
        DB::table('pos_order_tables')->insert(['order_id' => $order->id, 'table_id' => $table->id]);
        $before = $this->snapshot();
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.occupied', true)
            ->assertJsonPath('data.seating', null)->assertJsonPath('data.bill.uuid', $order->uuid);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_old_paid_bill_and_rounds_do_not_leak_into_new_party(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $old = $this->seatingRow($table, ['status' => 'closed']);
        $paid = $this->seatingOrder($old, ['status' => 'paid']);
        $this->seatingRound($old, $paid);
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.occupied', false)->assertJsonPath('data.bill', null)->assertJsonPath('data.rounds', []);
        $new = $this->seatingRow($table, ['temp_reference' => 'T-0912-002']);
        $before = $this->snapshot();
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.occupied', true)
            ->assertJsonPath('data.seating.uuid', $new->uuid)->assertJsonPath('data.bill', null)->assertJsonPath('data.rounds', []);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ambiguous_generations_refuse_instead_of_selecting_latest_bill(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $old = $this->seatingRow($table, ['status' => 'closed']);
        $this->seatingOrder($old);
        $new = $this->seatingRow($table);
        $this->seatingOrder($new);
        $before = $this->snapshot();
        $response = $this->detail($device, $table)->assertConflict()->assertJsonPath('errors.0.code', 'table_bill_conflict');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_foreign_company_branch_and_corrupt_floor_are_not_found(): void
    {
        $device = $this->seatingDevice();
        $foreign = $this->seatingTable('Other company', 20, 200);
        $otherBranch = $this->seatingTable('Other branch', 30);
        $broken = $this->seatingTable('Broken floor');
        DB::table('pos_floors')->where('id', $broken->floor_id)->update(['company_id' => 200]);
        $before = $this->snapshot();
        foreach ([$foreign, $otherBranch, $broken] as $table) {
            $this->detail($device, $table)->assertNotFound()->assertJsonPath('errors.0.code', 'table_not_found');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_station_and_unassigned_devices_are_refused_before_lookup(): void
    {
        $station = $this->seatingDevice('payment_station');
        $table = new Table(['id' => 999999]);
        $before = $this->snapshot();
        $this->detail($station, $table)->assertConflict()->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->assertSame($before, $this->snapshot());
        foreach ([['status' => 'inactive'], ['company_id' => null], ['branch_id' => null], ['deleted_at' => now()]] as $change) {
            $device = $this->seatingDevice()->forceFill($change);
            try {
                app(ReadTableDetailAction::class)->handle($device, 999999);
                $this->fail('Admission must precede table lookup.');
            } catch (QrDineInException $error) {
                $this->assertSame('device_not_attended', $error->codeName);
            }
        }
    }

    public function test_no_authentication_or_invalid_table_identifier_cannot_read(): void
    {
        $table = $this->seatingTable();
        $this->getJson('/api/v1/device/tables/'.$table->id.'/detail')->assertUnauthorized();
        $device = $this->seatingDevice();
        $this->withToken($device->device_token)->getJson('/api/v1/device/tables/not-a-number/detail')->assertNotFound();
    }

    public function test_canonical_link_to_other_tenant_bill_fails_closed_without_disclosure(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table);
        $foreign = $this->seatingRow($this->seatingTable('Other company', 20, 200));
        $order = $this->seatingOrder($foreign);
        $seat->update(['order_id' => $order->id]);
        $before = $this->snapshot();
        $this->detail($device, $table)->assertConflict()->assertJsonPath('errors.0.code', 'table_bill_conflict');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_priced_line_allowlist_does_not_expose_credentials_or_confirmation_payload(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table);
        $order = $this->seatingOrder($seat);
        $credential = $this->credential($seat);
        $order->update(['qr_session_id' => $credential->id]);
        $safe = ['product_id' => 9, 'product_name' => 'Synthetic water', 'qty' => 3, 'unit_price_baisas' => 333,
            'order_item_id' => 17, 'cancelled_qty' => 1, 'cancelled_discount_baisas' => 7,
            'addons' => [['add_on_id' => 3, 'name' => 'Ice', 'price_delta_baisas' => 0]],
            'cancellations' => [['qty' => 1, 'discount_baisas' => 7, 'at' => '2026-09-12T11:00:00Z']]];
        $unsafe = $safe + ['client_secret_hash' => 'DO-NOT-LEAK', 'phone' => 'DO-NOT-LEAK'];
        $unsafe['addons'][0]['private'] = 'DO-NOT-LEAK';
        $unsafe['cancellations'][0]['client_request_id'] = 'DO-NOT-LEAK';
        $this->seatingRound($seat, $order, ['qr_session_id' => $credential->id, 'priced_lines' => [$unsafe], 'confirm_payload' => ['private' => 'DO-NOT-LEAK']]);
        $before = $this->snapshot();
        $response = $this->detail($device, $table)->assertOk()->assertJsonPath('data.rounds.0.priced_lines.0', $safe);
        foreach (['DO-NOT-LEAK', 'confirm_payload', 'client_secret_hash', 'token', 'qr_session_id', 'latitude', 'longitude'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_read_query_count_is_constant_with_round_count_and_contains_no_write_or_lock(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table);
        $order = $this->seatingOrder($seat);
        $this->seatingRound($seat, $order);
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->detail($device, $table)->assertOk();
        $count = count($queries);
        for ($i = 0; $i < 30; $i++) {
            $this->seatingRound($seat, $order, ['round_no' => $i + 2]);
        }
        $before = $this->snapshot();
        $queries = [];
        $this->detail($device, $table)->assertOk()->assertJsonCount(31, 'data.rounds');
        $this->assertSame($count, count($queries));
        fwrite(STDOUT, "\nUNIFIED_TABLE_DETAIL_QUERY_COUNTS=".json_encode(['one_round' => $count, 'thirty_one_rounds' => count($queries)], JSON_THROW_ON_ERROR)."\n");
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select /i', $query);
            $this->assertStringNotContainsString('for update', strtolower($query));
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function charges(): iterable
    {
        yield 'in-flight' => [null, true, 'live_claim'];
        yield 'expired attempt' => [null, false, 'uncertain'];
        yield 'uncertain' => ['uncertain', true, 'uncertain'];
        yield 'lapsed' => ['lapsed', false, 'uncertain'];
        yield 'declined' => ['declined', true, 'declined'];
        yield 'cancelled' => ['cancelled', true, 'cancelled'];
        yield 'approved' => ['approved', true, 'uncertain'];
    }

    #[DataProvider('charges')]
    public function test_payment_evidence_is_display_only_and_never_released_or_repaired(?string $outcome, bool $future, string $display): void
    {
        $device = $this->seatingDevice();
        $other = $this->seatingDevice('handheld');
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table, ['status' => 'billing', 'billing_at' => now()->subMinute()]);
        $order = $this->seatingOrder($seat, ['status' => 'awaiting_payment', 'source' => 'qr_web',
            'charge_device_id' => $other->id, 'charge_claimed_at' => now()->subMinute(),
            'charge_deadline_at' => $future ? now()->addMinute() : now()->subSecond(),
            'charge_amount_baisas' => 1000, 'charge_outcome' => $outcome]);
        $before = $this->snapshot();
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.bill.charge', $display)
            ->assertJsonPath('data.bill.uuid', $order->uuid)->assertJsonPath('data.bill.status', 'awaiting_payment')
            ->assertJsonMissingPath('data.actions')->assertJsonMissingPath('data.bill.charge_device_id')
            ->assertJsonMissingPath('data.bill.charge_amount_baisas');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_reassignment_after_authentication_refuses_before_table_lookup(): void
    {
        $device = $this->seatingDevice();
        DB::table('pos_devices')->where('id', $device->id)->update(['branch_id' => 99]);
        $before = $this->snapshot();
        try {
            app(ReadTableDetailAction::class)->handle($device, 99999);
            $this->fail('A stale authenticated device cannot read its previous branch.');
        } catch (QrDineInException $error) {
            $this->assertSame('device_not_attended', $error->codeName);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_billless_pending_round_and_frozen_catalogue_hold_remain_visible(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table);
        $credential = $this->credential($seat);
        $customer = $this->seatingRound($seat, null, ['qr_session_id' => $credential->id, 'status' => 'pending_confirmation']);
        $heldLine = ['line_index' => 0, 'product_id' => 987, 'product_name' => 'Unavailable water', 'qty' => 2,
            'notes' => null, 'addon_ids' => [], 'requested' => true, 'held_reason' => 'product_unavailable',
            'addon_id' => null, 'unit_price_baisas' => null, 'line_total_baisas' => null];
        $staff = $this->seatingRound($seat, null, ['round_no' => 2, 'status' => 'pending_confirmation',
            'needs_review' => true, 'priced_lines' => [$heldLine], 'confirm_payload' => null]);
        $foreign = $this->seatingRow($this->seatingTable('Other tenant', 20, 200));
        $foreignBill = $this->seatingOrder($foreign);
        $this->seatingRound($seat, $foreignBill, ['round_no' => 3]);
        $before = $this->snapshot();
        $response = $this->detail($device, $table)->assertOk()->assertJsonPath('data.bill', null)
            ->assertJsonCount(2, 'data.rounds')->assertJsonPath('data.rounds.1.priced_lines', [$heldLine]);
        $this->assertSame([$customer->id, $staff->id], array_column($response->json('data.rounds'), 'id'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_billless_legacy_credential_does_not_expire_or_create_a_seating_on_read(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $historical = $this->seatingRow($table, ['status' => 'closed']);
        $credential = $this->credential($historical, ['table_session_id' => null]);
        $before = $this->snapshot();
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.occupied', true)
            ->assertJsonPath('data.seating', null)->assertJsonPath('data.credential.expired', false);
        $this->assertSame($before, $this->snapshot());
        $credential->update(['expires_at' => now()->subSecond()]);
        $before = $this->snapshot();
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.occupied', false)
            ->assertJsonPath('data.credential.status', 'active')->assertJsonPath('data.credential.expired', true);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_foreign_credential_and_rounds_do_not_enter_a_local_billless_table(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table);
        $other = $this->seatingRow($this->seatingTable('Other branch', 20));
        $credential = $this->credential($other, ['table_session_id' => $seat->id]);
        $this->seatingRound($other, null, ['qr_session_id' => $credential->id]);
        $before = $this->snapshot();
        $this->detail($device, $table)->assertOk()->assertJsonPath('data.credential', null)->assertJsonPath('data.rounds', []);
        $this->assertSame($before, $this->snapshot());
    }
}
