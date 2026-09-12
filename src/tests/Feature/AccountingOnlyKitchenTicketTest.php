<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ListAcceptedDineInQrRoundsAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ClaimKitchenTicketAction;
use App\Actions\Tables\CombineLegacyTableBillAction;
use App\Actions\Tables\RecordKitchenPrintResultAction;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class AccountingOnlyKitchenTicketTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
    }

    public static function accountingTickets(): array
    {
        $cases = [];
        foreach (['baseline', 'combined'] as $kind) {
            foreach (['missing', 'claimed', 'failed', 'printed'] as $ticket) {
                $cases[$kind.' '.$ticket] = [$kind, $ticket];
            }
        }

        return $cases;
    }

    #[DataProvider('accountingTickets')]
    public function test_accounting_round_cannot_be_claimed_replayed_or_recorded(string $kind, string $ticketState): void
    {
        $flow = $this->flow();
        if ($kind === 'baseline') {
            $lines = $flow['round']->priced_lines;
            $lines[0]['accounting_only'] = true;
            $lines[0]['legacy_hold_event_id'] = (string) Str::uuid();
            $flow['round']->update(['priced_lines' => $lines]);
        } else {
            $this->combineEvent($flow);
        }
        if ($ticketState !== 'missing') {
            KitchenTicket::query()->create([
                'company_id' => $flow['device']->company_id, 'branch_id' => $flow['device']->branch_id,
                'ticket_key' => $flow['key'], 'round_id' => $flow['round']->id, 'order_id' => $flow['order']->id,
                'claimed_by_device_id' => $flow['device']->id, 'claimed_at' => now()->subMinute(),
                'print_result' => $ticketState === 'claimed' ? null : $ticketState,
                'printed_at' => $ticketState === 'printed' ? now()->subMinute() : null,
            ]);
        }

        $before = $this->rows();
        $this->assertSame([], app(ListAcceptedDineInQrRoundsAction::class)->handle($flow['device'])['rounds']);
        $this->assertNotPrintable(fn () => $this->claim($flow));
        $this->assertSame($before, $this->rows(), 'A refused claim must preserve every raw business row.');
        $this->assertNotPrintable(fn () => $this->record($flow));
        $this->assertSame($before, $this->rows(), 'A refused print result must preserve every raw business row.');
    }

    public function test_ordinary_legacy_nullable_sequence_round_remains_claimable_and_printable(): void
    {
        $flow = $this->flow();
        $flow['round']->update(['client_request_id' => 'combine:'.Str::uuid()]);
        $this->assertNull($flow['round']->fresh()->accepted_seq);
        $this->assertFalse($this->claim($flow)['replayed']);
        $this->assertTrue($this->claim($flow)['replayed']);
        $this->assertSame('printed', $this->record($flow)['print_result']);
        $this->assertNotNull($flow['round']->fresh()->kitchen_printed_at);
        $this->assertSame(1, KitchenTicket::query()->count());
    }

    public static function unrelatedEvidence(): array
    {
        $cases = [];
        foreach (['company', 'branch', 'seating', 'event_type', 'action', 'bill', 'result_bill', 'round'] as $field) {
            $cases[$field] = [$field];
        }

        return $cases;
    }

    #[DataProvider('unrelatedEvidence')]
    public function test_only_the_import_journal_for_this_tenant_seating_bill_and_round_suppresses_printing(string $field): void
    {
        $flow = $this->flow();
        $event = $this->combineEvent($flow);
        $payload = $event->payload;
        $attributes = [];
        switch ($field) {
            case 'company':
                $attributes['company_id'] = 200;
                break;
            case 'branch':
                $this->seatingBranch(20);
                $attributes['branch_id'] = 20;
                break;
            case 'seating':
                $attributes['table_session_id'] = $this->seatingRow($this->seatingTable())->id;
                break;
            case 'event_type':
                $attributes['event_type'] = 'attached';
                break;
            case 'action':
                $payload['action'] = 'seating_merged';
                break;
            case 'bill':
                $payload['order_uuid'] = (string) Str::uuid();
                break;
            case 'result_bill':
                $payload['result']['order_uuid'] = (string) Str::uuid();
                break;
            case 'round':
                $payload['result']['round_id'] = (int) $flow['round']->id + 1000;
                break;
        }
        $event->update($attributes + ['payload' => $payload]);

        $this->assertFalse($this->claim($flow)['replayed']);
        $this->assertSame('printed', $this->record($flow)['print_result']);
    }

    public function test_real_combine_import_is_not_printable_without_a_line_marker(): void
    {
        $flow = $this->flow();
        $station = $this->seatingDevice('payment_station');
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $station->id, 'table_id' => $flow['table']->id,
            'table_session_id' => $flow['seating']->id, 'status' => QrSession::STATUS_ACTIVE,
            'token' => hash('sha256', (string) Str::uuid()), 'token_expires_at' => now()->addHours(6),
            'expires_at' => now()->addHours(6),
        ]);
        $flow['order']->update(['source' => Order::SOURCE_QR_WEB, 'qr_session_id' => $session->id]);
        $source = Order::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $flow['device']->id, 'table_id' => $flow['table']->id,
            'order_type' => 'dine_in', 'source' => 'main_pos', 'status' => Order::STATUS_HELD,
            'subtotal' => '1.000', 'discount_total' => '0.000', 'comp_total' => '0.000',
            'tax_total' => '0.000', 'grand_total' => '1.000',
            'opened_at' => now()->subMinutes(20), 'temp_reference' => 'OLD-PRINT',
        ]);
        $originalItem = (array) DB::table('pos_order_items')->where('order_id', $flow['order']->id)->sole();
        DB::table('pos_order_items')->insert(array_replace(Arr::except($originalItem, ['id']), ['order_id' => $source->id]));
        DB::table('pos_staff')->insert([
            'id' => 700, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Print test manager', 'pin_hash' => Hash::make('4321'), 'position' => 'manager',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $combines = app(CombineLegacyTableBillAction::class);
        $preview = $combines->preview($flow['device'], $flow['table']->id, $source->uuid);
        $result = $combines->handle($flow['device'], $flow['table']->id, [
            'source_order_uuid' => $source->uuid, 'target_order_uuid' => $flow['order']->uuid,
            'client_request_id' => (string) Str::uuid(), 'preview_token' => $preview['preview_token'],
            'reason' => 'same_party_duplicate_bill', 'pin' => '4321',
        ]);
        $flow['round'] = QrOrderRound::query()->findOrFail($result['round_id']);
        $flow['key'] = 'round:'.$flow['round']->id;
        $this->assertNull($flow['round']->accepted_seq);
        $this->assertArrayNotHasKey('accounting_only', $flow['round']->priced_lines[0]);

        $before = $this->rows();
        $this->assertSame([], app(ListAcceptedDineInQrRoundsAction::class)->handle($flow['device'])['rounds']);
        $this->assertNotPrintable(fn () => $this->claim($flow));
        $this->assertNotPrintable(fn () => $this->record($flow));
        $this->assertSame($before, $this->rows());
    }

    private function flow(): array
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seating = $this->seatingRow($table, ['opened_by_device_id' => $device->id]);
        $order = $this->seatingOrder($seating);
        $product = $this->seatingProduct();
        $itemId = DB::table('pos_order_items')->insertGetId([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => 'Frozen coffee',
            'qty' => '1.000', 'unit_price_snapshot' => '1.000', 'line_discount' => '0.000', 'line_total' => '1.000',
            'recipe_snapshot_json' => '[]', 'component_snapshot_json' => '[]', 'notes' => null,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $round = $this->seatingRound($seating, $order, [
            'accepted_seq' => null, 'confirm_payload' => null, 'needs_review' => false,
            'priced_lines' => [[
                'line_index' => 0, 'order_item_id' => $itemId, 'product_id' => $product->id,
                'product_name' => 'Frozen coffee', 'qty' => 1, 'notes' => null, 'addons' => [],
                'unit_price_baisas' => 1000, 'line_total_baisas' => 1000,
            ]],
        ]);
        $key = 'round:'.$round->id;

        return compact('device', 'table', 'seating', 'order', 'round', 'key');
    }

    private function combineEvent(array $flow): TableSessionEvent
    {
        return TableSessionEvent::query()->create([
            'company_id' => $flow['device']->company_id, 'branch_id' => $flow['device']->branch_id,
            'table_session_id' => $flow['seating']->id, 'table_id' => $flow['table']->id,
            'device_id' => $flow['device']->id, 'event_type' => 'merged', 'created_at' => now(),
            'payload' => [
                'action' => 'legacy_bill_combined', 'order_uuid' => $flow['order']->uuid,
                'result' => ['order_uuid' => $flow['order']->uuid, 'round_id' => (int) $flow['round']->id],
            ],
        ]);
    }

    private function claim(array $flow): array
    {
        return app(ClaimKitchenTicketAction::class)->handle($flow['device'], ['ticket_key' => $flow['key']]);
    }

    private function record(array $flow): array
    {
        return app(RecordKitchenPrintResultAction::class)->handle($flow['device'], [
            'ticket_key' => $flow['key'], 'print_result' => 'printed', 'printed_at' => now()->toIso8601String(),
        ]);
    }

    private function assertNotPrintable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Accounting-only rounds must not be printed.');
        } catch (QrDineInException $exception) {
            $this->assertSame('kitchen_round_not_printable', $exception->codeName);
            $this->assertSame(409, $exception->httpStatus);
        }
    }

    private function rows(): array
    {
        $snapshot = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'pos_%' ORDER BY name") as $table) {
            $snapshot[$table->name] = DB::table($table->name)->get()
                ->map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
        }

        return $snapshot;
    }
}
