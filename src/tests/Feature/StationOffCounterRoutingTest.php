<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class StationOffCounterRoutingTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    private const CHARGE_FIELDS = ['charge_device_id', 'charge_amount_baisas', 'charge_roundup_amount_baisas',
        'charge_claimed_at', 'charge_deadline_at', 'charge_outcome'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        DB::table('pos_company_settings')->insert([
            'company_id' => 100, 'key' => 'order_numbering',
            'value' => json_encode([
                'enabled' => true, 'prefix' => 'T4-', 'pad' => 5, 'scope' => 'branch', 'daily_reset' => false,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[DataProvider('stationOffCases')]
    public function test_station_off_safe_routing_freezes_the_bill_and_survives_counter_settlement(string $status, string $condition): void
    {
        [$caller, $station, $session, $seating, $order] = $this->fixture($status);
        $this->makeUnusable($station, $session, $condition);
        $order->refresh();
        $this->seatingRound($seating, $order, ['status' => 'pending_confirmation', 'confirm_payload' => ['preserve' => true]]);
        $roundsBefore = QrOrderRound::query()->orderBy('id')->get()->toArray();
        $billingBefore = $seating->fresh()->getRawOriginal('billing_at');
        $response = $this->qrPost($caller, 'fallback-to-counter', ['order_uuid' => $order->uuid])
            ->assertOk()->assertJsonPath('data.status', 'held')->assertJsonPath('data.receipt_number', null)
            ->assertJsonPath('data.temp_reference', 'T-0905-001');
        $this->assertSame($roundsBefore, QrOrderRound::query()->orderBy('id')->get()->toArray());
        $this->assertSame('held', $order->fresh()->status);
        $this->assertSame('billing', $seating->fresh()->status);
        $this->assertSame($status === 'open' ? now()->format('Y-m-d H:i:s') : $billingBefore, $seating->fresh()->getRawOriginal('billing_at'));
        $this->assertNull($order->fresh()->receipt_number);
        $this->assertDatabaseCount('pos_order_sequences', 0);
        foreach (self::CHARGE_FIELDS as $field) {
            $this->assertNull($order->fresh()->getRawOriginal($field));
        }
        $sessionAfter = QrSession::query()->find($session->id);
        $this->assertSame($condition === 'deleted' ? null : ($condition === 'expired' ? 'expired' : 'ordered'), $sessionAfter?->status);
        $eventsBefore = TableSessionEvent::query()->orderBy('id')->get()->toArray();
        $this->qrPost($caller, 'fallback-to-counter', ['order_uuid' => $order->uuid])->assertOk();
        $this->assertSame($eventsBefore, TableSessionEvent::query()->orderBy('id')->get()->toArray());
        $this->qrPost($caller, 'claim-settlement', ['order_uuid' => $order->uuid])->assertOk();
        $this->assertSame('awaiting_payment', $order->fresh()->status);
        app('auth')->forgetGuards();
        $paid = $this->withToken($caller->device_token)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(), 'payload' => [
                'order_uuid' => $order->uuid, 'paid_at' => now()->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => 1000, 'status' => 'success']],
            ],
        ]]])->assertOk();
        $this->assertSame('processed', $paid->json('data.results.0.status'), $paid->getContent());
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->receipt_number);
        $this->assertSame('closed', $seating->fresh()->status);
        if ($status === 'open' && $condition === 'inactive') {
            fwrite(STDOUT, "\nT4_D7_COUNTER_JSON=".$response->getContent()."\n");
        }
    }

    public static function stationOffCases(): array
    {
        $cases = [];
        foreach (['open', 'awaiting_payment'] as $status) {
            foreach (['null_station', 'deleted', 'trashed', 'inactive', 'unassigned', 'other_branch', 'wrong_type', 'expired'] as $condition) {
                $cases[] = [$status, $condition];
            }
        }

        return $cases;
    }

    #[DataProvider('ambiguousCases')]
    public function test_ambiguous_charge_precedence_preserves_all_six_provenance_fields(string $status, ?string $outcome): void
    {
        [$caller, $station, $session, $seating, $order] = $this->fixture($status);
        $this->makeUnusable($station, $session, 'inactive');
        $order->update([
            'charge_device_id' => $station->id, 'charge_amount_baisas' => 1000,
            'charge_roundup_amount_baisas' => 10, 'charge_claimed_at' => now()->subMinutes(5),
            'charge_deadline_at' => now()->subMinute(), 'charge_outcome' => $outcome,
        ]);
        $before = array_intersect_key($order->fresh()->getRawOriginal(), array_flip(self::CHARGE_FIELDS));
        $this->qrPost($caller, 'fallback-to-counter', ['order_uuid' => $order->uuid])->assertOk()->assertJsonPath('data.status', 'held');
        $this->assertSame($before, array_intersect_key($order->fresh()->getRawOriginal(), array_flip(self::CHARGE_FIELDS)));
        $this->qrPost($caller, 'claim-settlement', ['order_uuid' => $order->uuid])->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');
        $this->assertSame($before, array_intersect_key($order->fresh()->getRawOriginal(), array_flip(self::CHARGE_FIELDS)));
        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    public static function ambiguousCases(): array
    {
        return [['open', 'uncertain'], ['awaiting_payment', 'uncertain'], ['awaiting_payment', 'lapsed'], ['awaiting_payment', null]];
    }

    public function test_usable_station_unadopted_staff_bill_and_live_or_approved_claims_remain_refused(): void
    {
        [$caller, $station, $session, $seating, $order] = $this->fixture('open');
        $before = $order->fresh()->getRawOriginal();
        $this->qrPost($caller, 'fallback-to-counter', ['order_uuid' => $order->uuid])->assertStatus(409);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->makeUnusable($station, $session, 'inactive');
        $order->update(['source' => 'main_pos', 'qr_session_id' => null]);
        $before = $order->fresh()->getRawOriginal();
        $this->qrPost($caller, 'fallback-to-counter', ['order_uuid' => $order->uuid])->assertStatus(409);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $order->update(['source' => 'qr_web', 'qr_session_id' => $session->id, 'status' => 'awaiting_payment']);
        foreach ([null, 'approved'] as $outcome) {
            $order->update(['charge_device_id' => $station->id, 'charge_amount_baisas' => 1000,
                'charge_claimed_at' => now(), 'charge_deadline_at' => now()->addMinutes(5), 'charge_outcome' => $outcome]);
            $before = $order->fresh()->getRawOriginal();
            $this->qrPost($caller, 'fallback-to-counter', ['order_uuid' => $order->uuid])->assertStatus(409)
                ->assertJsonPath('errors.0.code', 'charge_already_claimed');
            $this->assertSame($before, $order->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('pos_table_session_events', 0);
    }

    private function fixture(string $status): array
    {
        $caller = $this->seatingDevice();
        $station = $this->seatingDevice('payment_station');
        $seating = $this->seatingRow($this->seatingTable(), [
            'opened_by_device_id' => $station->id, 'origin' => 'station',
            'status' => $status === 'open' ? 'open' : 'billing',
            'billing_at' => $status === 'open' ? null : now()->subMinute(),
        ]);
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $station->id,
            'table_id' => $seating->table_id, 'table_session_id' => $seating->id,
            'token' => Str::random(64), 'token_expires_at' => now()->addHour(),
            'status' => $status === 'open' ? 'active' : 'ordered', 'expires_at' => now()->addHours(6),
        ]);
        $customer = Customer::query()->create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'D7 customer', 'phone' => '91234567']);
        $order = $this->seatingOrder($seating, ['status' => $status, 'source' => 'qr_web',
            'qr_session_id' => $session->id, 'customer_id' => $customer->id]);
        $this->seatingRound($seating, $order);

        return [$caller, $station, $session, $seating, $order];
    }

    private function makeUnusable(Device $station, QrSession $session, string $condition): void
    {
        match ($condition) {
            'null_station' => $session->update(['device_id' => null]),
            'deleted' => $station->forceDelete(),
            'trashed' => $station->delete(),
            'inactive' => $station->forceFill(['status' => 'inactive'])->save(),
            'unassigned' => $station->forceFill(['branch_id' => null])->save(),
            'other_branch' => $station->forceFill(['branch_id' => $this->seatingBranch(20)->id])->save(),
            'wrong_type' => $station->forceFill(['device_type' => 'handheld'])->save(),
            'expired' => $session->update(['expires_at' => now()->subSecond()]),
        };
    }

    private function qrPost(Device $device, string $operation, array $payload): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withToken($device->device_token)->postJson('/api/v1/device/qr/'.$operation, $payload);
    }
}
