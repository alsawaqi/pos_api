<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\CloseTableSessionForOrderAction;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class PaidBillPendingCleanupTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public static function terminalEvents(): array
    {
        return [['order.pay', 'paid', 'paid'], ['order.void', 'voided', 'void']];
    }

    #[DataProvider('terminalEvents')]
    public function test_pay_and_void_reject_pending_bill_rounds_without_changing_money_or_accepted_rows(
        string $type,
        string $reason,
        string $status,
    ): void {
        $device = $this->seatingDevice();
        $primary = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $order = $this->seatingOrder($primary);
        $joined = $this->seatingRow($this->seatingTable('Joined'), [
            'opened_by_device_id' => $device->id, 'merged_into_id' => $primary->id,
            'order_id' => $order->id,
        ]);
        DB::table('pos_order_tables')->insert(['order_id' => $order->id, 'table_id' => $joined->table_id]);
        $alias = $this->seatingRow($this->seatingTable('Alias'), [
            'status' => 'merged', 'merged_into_id' => $primary->id, 'close_reason' => 'merged',
        ]);
        $pending = [];
        foreach ([null, $alias->id] as $index => $originId) {
            $pending[] = $this->seatingRound($primary, $order, [
                'round_no' => $index + 1, 'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
                'needs_review' => true, 'origin_table_session_id' => $originId,
                'confirm_payload' => ['stored' => $index], 'resolved_at' => null,
            ]);
        }
        $accepted = $this->seatingRound($primary, $order, ['round_no' => 3]);
        $acceptedBefore = $accepted->fresh()->getRawOriginal();
        $aliasBefore = $alias->fresh()->getRawOriginal();
        $unrelated = $this->seatingRow($this->seatingTable('Another bill'), ['opened_by_device_id' => $device->id]);
        $otherOrder = $this->seatingOrder($unrelated);
        $otherRound = $this->seatingRound($unrelated, $otherOrder, [
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
            'confirm_payload' => ['must_remain' => true],
        ]);
        $otherBefore = $otherRound->fresh()->getRawOriginal();
        $money = $order->fresh()->only(['subtotal', 'discount_total', 'comp_total', 'tax_total', 'grand_total']);
        $payload = ['order_uuid' => $order->uuid];
        $payload += $type === 'order.pay'
            ? ['paid_at' => now()->toIso8601String(), 'payments' => [
                ['method' => 'cash', 'amount_baisas' => 1000, 'status' => 'success'],
            ]]
            : ['voided_at' => now()->toIso8601String(), 'reason' => 'Synthetic T5 cancellation'];
        $event = [
            'client_event_id' => (string) Str::uuid(), 'event_type' => $type,
            'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
        ];
        $response = $this->withToken($device->plainTextToken)
            ->postJson('/api/v1/device/sync/push', ['events' => [$event]])->assertOk();
        $this->assertSame('processed', $response->json('data.results.0.status'), $response->getContent());
        $this->assertSame($status, $order->fresh()->status);
        $this->assertSame($money, $order->fresh()->only(array_keys($money)));
        $events = TableSessionEvent::query()->where('event_type', 'round_resolved')->orderBy('id')->get();
        $this->assertCount(2, $events);
        foreach ($pending as $index => $round) {
            $round->refresh();
            $this->assertSame('rejected', $round->status);
            $this->assertNull($round->confirm_payload);
            $this->assertSame(now()->toIso8601String(), $round->resolved_at->toIso8601String());
            $this->assertSame((int) $device->id, (int) $round->resolved_by_device_id);
            $this->assertSame((int) $primary->id, (int) $events[$index]->table_session_id);
            $this->assertSame((int) $round->id, $events[$index]->payload['round_id']);
            $this->assertSame('rejected', $events[$index]->payload['outcome']);
            $this->assertSame('bill_'.$reason, $events[$index]->payload['reason']);
            $this->assertSame((int) $device->id, (int) $events[$index]->device_id);
        }
        $this->assertSame('closed', $primary->fresh()->status);
        $this->assertSame('closed', $joined->fresh()->status);
        $this->assertSame($acceptedBefore, $accepted->fresh()->getRawOriginal());
        $this->assertSame($aliasBefore, $alias->fresh()->getRawOriginal());
        $this->assertSame($otherBefore, $otherRound->fresh()->getRawOriginal());
        $rowsBefore = QrOrderRound::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $journalBefore = TableSessionEvent::query()->orderBy('id')->get()->toArray();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.duplicate', true);
        $this->assertSame(0, DB::transaction(fn (): int => app(CloseTableSessionForOrderAction::class)->handle(
            Order::query()->lockForUpdate()->findOrFail($order->id), now()->addMinute(), $reason, (int) $device->id,
        )));
        $this->assertSame($rowsBefore, QrOrderRound::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($journalBefore, TableSessionEvent::query()->orderBy('id')->get()->toArray());
        fwrite(STDOUT, "\nT5_PENDING_CLEANUP_JSON=".json_encode([
            'event_type' => $type, 'status' => $order->fresh()->status,
            'money' => $money, 'pending_count' => 0,
            'resolved_events' => $events->toArray(),
        ], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_non_payment_close_does_not_resolve_rounds(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $order = $this->seatingOrder($seating);
        $round = $this->seatingRound($seating, $order, [
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION, 'confirm_payload' => ['keep' => true],
        ]);
        $before = $round->fresh()->getRawOriginal();
        DB::transaction(fn (): int => app(CloseTableSessionForOrderAction::class)->handle(
            $order, now(), 'cleared', null,
        ));
        $this->assertSame($before, $round->fresh()->getRawOriginal());
        $this->assertSame(0, TableSessionEvent::query()->where('event_type', 'round_resolved')->count());
    }
}
