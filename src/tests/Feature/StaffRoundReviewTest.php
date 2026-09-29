<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\OpenStaffTableSessionAction;
use App\Models\Device;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class StaffRoundReviewTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    #[DataProvider('printEvidence')]
    public function test_merged_confirm_appends_frozen_children_once_and_preserves_review_and_print_evidence(bool $printed): void
    {
        [$device, $seating, $round, $product] = $this->pendingFixture($printed);
        $bill = $seating->fresh()->order;
        $oldItems = OrderItem::query()->where('order_id', $bill->id)->orderBy('id')->get()->toArray();
        $snapshot = $round->getRawOriginal();
        $product->update(['base_price' => '55.000', 'status' => 'inactive']);
        $ack = $this->review($device, $seating->uuid, $round->id, 'confirm', 'accepted');
        $this->assertSame('2.000', $bill->fresh()->grand_total);
        $items = OrderItem::query()->where('order_id', $bill->id)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame($oldItems[0], $items[0]->toArray());
        $this->assertSame('1.000', $items[1]->unit_price_snapshot);
        $expectedLines = json_decode($snapshot['priced_lines'], true, flags: JSON_THROW_ON_ERROR);
        $expectedLines[0]['order_item_id'] = (int) $items[1]->id;
        $this->assertSame(json_encode($expectedLines, JSON_THROW_ON_ERROR), $round->fresh()->getRawOriginal('priced_lines'));
        $this->assertSame($snapshot['kitchen_printed_at'], $round->fresh()->getRawOriginal('kitchen_printed_at'));
        $this->assertSame($snapshot['origin_table_session_id'], $round->fresh()->getRawOriginal('origin_table_session_id'));
        $this->assertTrue($ack['needs_review']);
        $this->assertSame(['merged'], $ack['review_reasons']);
        $this->assertSame([], $ack['held_lines']);
        $this->assertSame([], $ack['dropped_lines']);
        $this->assertSame(! $printed, $ack['print_pending']);
        $this->assertNull($round->fresh()->confirm_payload);
        $this->assertNotNull($round->fresh()->accepted_seq);
        $this->assertSame((int) $device->id, (int) $round->fresh()->resolved_by_device_id);
        $event = TableSessionEvent::findOrFail($ack['event_id']);
        $this->assertSame('round_resolved', $event->event_type);
        $this->assertSame(0, $event->payload['dropped_line_count']);
        $this->assertArrayNotHasKey('lines', $event->payload);
        $this->assertArrayNotHasKey('confirm_payload', $ack);
        $billBeforeReplay = $bill->fresh()->getRawOriginal();
        $roundBeforeReplay = $round->fresh()->getRawOriginal();
        $eventCount = TableSessionEvent::query()->count();
        $replay = $this->review($device, $seating->uuid, $round->id, 'confirm', 'replayed');
        $this->assertNull($replay['event_id']);
        $this->assertSame($billBeforeReplay, $bill->fresh()->getRawOriginal());
        $this->assertSame($roundBeforeReplay, $round->fresh()->getRawOriginal());
        $this->assertSame($eventCount, TableSessionEvent::query()->count());
        $this->assertSame(2, OrderItem::query()->where('order_id', $bill->id)->count());
        fwrite(STDOUT, "\nT4_REVIEW_MERGED_".($printed ? 'PRINTED' : 'UNPRINTED').'_JSON='.json_encode($ack, JSON_THROW_ON_ERROR)."\n");
    }

    public static function printEvidence(): array
    {
        return [[false], [true]];
    }

    public function test_reject_preserves_money_children_and_entire_frozen_request_and_cannot_then_be_confirmed(): void
    {
        [$device, $seating, $round] = $this->pendingFixture();
        $bill = $seating->fresh()->order;
        $beforeBill = $bill->getRawOriginal();
        $beforeLines = $round->priced_lines;
        $beforeItems = OrderItem::query()->where('order_id', $bill->id)->get()->toArray();
        $ack = $this->review($device, $seating->uuid, $round->id, 'reject', 'rejected');
        $this->assertSame($beforeBill, $bill->fresh()->getRawOriginal());
        $this->assertSame($beforeItems, OrderItem::query()->where('order_id', $bill->id)->get()->toArray());
        $this->assertSame($beforeLines, $round->fresh()->priced_lines);
        $this->assertNull($round->fresh()->confirm_payload);
        $this->assertNull($round->fresh()->accepted_seq);
        $this->assertTrue($ack['needs_review']);
        $this->assertFalse($ack['print_pending']);
        $this->assertSame([], $ack['dropped_lines']);
        $this->assertSame('rejected', $ack['round_status']);
        $count = TableSessionEvent::query()->count();
        $this->review($device, $seating->uuid, $round->id, 'reject', 'replayed');
        $this->review($device, $seating->uuid, $round->id, 'confirm', 'replayed');
        $this->assertSame($count, TableSessionEvent::query()->count());
        $this->assertSame($beforeBill, $bill->fresh()->getRawOriginal());
        fwrite(STDOUT, "\nT4_REVIEW_REJECT_JSON=".json_encode($ack, JSON_THROW_ON_ERROR)."\n");
    }

    #[DataProvider('frozenBills')]
    public function test_confirm_frozen_or_terminal_bill_returns_a_200_verdict_without_mutation(string $status, string $outcome): void
    {
        [$device, $seating, $round] = $this->pendingFixture();
        $bill = $seating->fresh()->order;
        $bill->update(['status' => $status]);
        $beforeBill = $bill->getRawOriginal();
        $beforeRound = $round->getRawOriginal();
        $count = TableSessionEvent::query()->count();
        $ack = $this->review($device, $seating->uuid, $round->id, 'confirm', $outcome);
        $this->assertNull($ack['event_id']);
        $this->assertSame($beforeBill, $bill->fresh()->getRawOriginal());
        $this->assertSame($beforeRound, $round->fresh()->getRawOriginal());
        $this->assertSame($count, TableSessionEvent::query()->count());
    }

    public static function frozenBills(): array
    {
        return [['held', 'bill_unpaid'], ['awaiting_payment', 'bill_unpaid'], ['paid', 'bill_terminal'], ['void', 'bill_terminal']];
    }

    public function test_joined_member_review_resolves_only_its_primary_bill_and_round(): void
    {
        [$device, $primary, $round] = $this->pendingFixture();
        $joined = $this->seatingRow($this->seatingTable('Joined table'), [
            'merged_into_id' => $primary->id, 'order_id' => $primary->fresh()->order_id,
        ]);
        $ack = $this->review($device, $joined->uuid, $round->id, 'confirm', 'accepted');
        $this->assertSame($primary->uuid, $ack['table_session_uuid']);
        $this->assertSame('2.000', $primary->fresh()->order->grand_total);
        $other = $this->seatingRow($this->seatingTable('Other party'));
        $otherBill = $this->seatingOrder($other);
        $before = $otherBill->fresh()->getRawOriginal();
        $this->withToken($device->plainTextToken)->postJson($this->url($other->uuid, $round->id, 'confirm'))->assertNotFound();
        $this->assertSame($before, $otherBill->fresh()->getRawOriginal());
    }

    public function test_numeric_foreign_branch_and_nonattended_reviews_are_refused_without_writes(): void
    {
        [$device, $seating, $round] = $this->pendingFixture();
        $before = $round->getRawOriginal();
        $count = TableSessionEvent::query()->count();
        $this->withToken($device->plainTextToken)->postJson($this->url((string) $seating->id, $round->id, 'confirm'))->assertNotFound();
        $foreign = $this->seatingDevice('fixed_pos', 20);
        app('auth')->forgetGuards();
        $this->withToken($foreign->plainTextToken)->postJson($this->url($seating->uuid, $round->id, 'confirm'))->assertNotFound();
        $station = $this->seatingDevice('payment_station');
        app('auth')->forgetGuards();
        $this->withToken($station->plainTextToken)->postJson($this->url($seating->uuid, $round->id, 'confirm'))->assertStatus(409);
        $this->assertSame($before, $round->fresh()->getRawOriginal());
        $this->assertSame($count, TableSessionEvent::query()->count());
    }

    public function test_new_review_path_cannot_bypass_customer_credential_expiry(): void
    {
        [$device, $seating, $round] = $this->pendingFixture();
        $station = $this->seatingDevice('payment_station');
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $station->id, 'table_id' => $seating->table_id,
            'table_session_id' => $seating->id, 'token' => hash('sha256', (string) Str::uuid()),
            'status' => 'expired', 'expires_at' => now()->subMinute(), 'token_expires_at' => now()->subMinute(),
        ]);
        $round->update(['qr_session_id' => $session->id]);
        $before = $round->getRawOriginal();
        $beforeBill = $seating->fresh()->order->getRawOriginal();
        foreach (['confirm', 'reject'] as $operation) {
            $this->withToken($device->plainTextToken)->postJson($this->url($seating->uuid, $round->id, $operation))
                ->assertNotFound()->assertJsonPath('errors.0.code', 'table_round_not_found');
        }
        $this->assertSame($before, $round->fresh()->getRawOriginal());
        $this->assertSame($beforeBill, $seating->fresh()->order->getRawOriginal());
    }

    private function pendingFixture(bool $printed = false): array
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $lines = [['product_id' => (int) $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]];
        app(AppendStaffRoundAction::class)->handle($device, [
            'seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id,
            'queued_offline' => false, 'client_request_id' => (string) Str::uuid(),
            'submitted_at' => now()->toIso8601String(), 'lines' => $lines,
        ], now(), now());
        $key = (string) Str::uuid();
        $common = ['seating_key' => $key, 'table_id' => (int) $seating->table_id, 'queued_offline' => true];
        app(OpenStaffTableSessionAction::class)->handle($device, $common + ['opened_at' => now()->toIso8601String()], now(), now());
        $ack = app(AppendStaffRoundAction::class)->handle($device, $common + [
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'printed_at' => $printed ? now()->subMinute()->toIso8601String() : null, 'lines' => $lines,
        ], now(), now());

        return [$device, $seating, QrOrderRound::findOrFail($ack['round_id']), $product];
    }

    private function review(Device $device, string $uuid, int $roundId, string $operation, string $outcome): array
    {
        app('auth')->forgetGuards();

        return $this->withToken($device->plainTextToken)->postJson($this->url($uuid, $roundId, $operation))
            ->assertOk()->assertJsonPath('data.outcome', $outcome)->json('data');
    }

    private function url(string $uuid, int $roundId, string $operation): string
    {
        return '/api/v1/device/tables/'.$uuid.'/rounds/'.$roundId.'/'.$operation;
    }
}
