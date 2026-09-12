<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ListStationQrAwaitingOrdersAction;
use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Http\Controllers\Api\V1\PublicQr\QrStatusController;
use App\Models\Floor;
use App\Models\QrSession;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QrPendingTestCase;

final class QrAttendedCancellationRoutingTest extends QrPendingTestCase
{
    public static function attendedCases(): iterable
    {
        foreach (['fixed_pos', 'handheld'] as $type) {
            foreach (['quick', 'dine_in'] as $kind) {
                foreach (['cancelled', 'declined'] as $outcome) {
                    yield "$type/$kind/$outcome" => [$type, $kind, $outcome];
                }
            }
        }
    }

    #[DataProvider('attendedCases')]
    public function test_attended_release_is_not_a_station_routing_instruction(string $type, string $kind, string $outcome): void
    {
        $holder = $type === 'fixed_pos' ? $this->till : $this->device($type);
        $order = $this->order();
        if ($kind === 'quick') {
            $this->claim($order, $holder)->assertOk();
        } else {
            $floor = Floor::create(['uuid' => (string) Str::uuid(), 'company_id' => 100,
                'branch_id' => 10, 'name' => 'Test floor', 'status' => 'active']);
            $table = Table::create(['uuid' => (string) Str::uuid(), 'company_id' => 100,
                'floor_id' => $floor->id, 'label' => 'T1', 'seats' => 4, 'shape' => 'square',
                'status' => 'active', 'qr_token' => hash('sha256', 'test-table')]);
            QrSession::whereKey($order->qr_session_id)->update(['table_id' => $table->id]);
            $order->update(['order_type' => 'dine_in', 'table_id' => $table->id,
                'status' => 'awaiting_payment', 'charge_device_id' => $holder->id,
                'charge_claimed_at' => now(), 'charge_deadline_at' => now()->addMinutes(5),
                'charge_amount_baisas' => 4750]);
        }
        $beforeRelease = $this->raw($order);
        $this->postAs($holder, '/api/v1/device/qr/release-charge', [
            'order_uuid' => $order->uuid, 'outcome' => $outcome,
        ])->assertOk()->assertJsonPath('data.charge_outcome', $outcome);
        $afterRelease = $this->raw($order);
        $this->assertSame($beforeRelease, array_replace($afterRelease, [
            'charge_outcome' => $beforeRelease['charge_outcome'], 'updated_at' => $beforeRelease['updated_at'],
        ]));
        $this->assertSame([], app(ListStationQrAwaitingOrdersAction::class)->handle($this->station));
        $snapshot = $this->snapshot();
        $this->postAs($this->station, '/api/v1/device/qr/claim-charge', ['order_uuid' => $order->uuid])
            ->assertConflict()->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertSame($snapshot, $this->snapshot());
        $session = QrSession::findOrFail($order->qr_session_id);
        $row = app(PresentQrPendingOrderAction::class)->handle($order->refresh()->load('items.addons', 'comps'), $session, null, now());
        $this->assertSame('counter', $row['route']);
        if ($kind === 'dine_in') {
            $request = Request::create('/api/v1/public/qr/status');
            $request->attributes->set('qr_session', $session);
            $data = app(QrStatusController::class)($request)->getData(true);
            $this->assertSame('awaiting_counter', $data['data']['dine_in']['payment_state']);
        } else {
            // Explicit staff counter recovery remains the shipped route. It
            // clears affirmative cancellation only, then permits a fresh claim.
            $this->move($order, $holder)->assertOk()->assertJsonPath('data.status', 'held');
            $this->claim($order, $holder)->assertOk();
        }
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public static function stationOutcomes(): iterable
    {
        yield 'cancelled' => ['cancelled'];
        yield 'declined' => ['declined'];
    }

    #[DataProvider('stationOutcomes')]
    public function test_station_cancellation_keeps_its_existing_retry_path(string $outcome): void
    {
        config(['qr.station_geofence_exempt' => true]);
        $order = $this->order(['status' => 'awaiting_payment']);
        $this->postAs($this->station, '/api/v1/device/qr/claim-charge', ['order_uuid' => $order->uuid])->assertOk();
        $this->postAs($this->station, '/api/v1/device/qr/release-charge', [
            'order_uuid' => $order->uuid, 'outcome' => $outcome,
        ])->assertOk();
        $this->assertSame($order->uuid, app(ListStationQrAwaitingOrdersAction::class)->handle($this->station)[0]['order_uuid']);
        $this->postAs($this->station, '/api/v1/device/qr/claim-charge', ['order_uuid' => $order->uuid])->assertOk();
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public static function missingHolderCases(): iterable
    {
        yield 'missing' => ['missing'];
        yield 'reassigned' => ['reassigned'];
        yield 'unassigned' => ['unassigned'];
    }

    #[DataProvider('missingHolderCases')]
    public function test_missing_or_reassigned_holder_never_turns_a_cancel_into_station_routing(string $case): void
    {
        $order = $this->order();
        $this->claim($order)->assertOk();
        $this->postAs($this->till, '/api/v1/device/qr/release-charge', [
            'order_uuid' => $order->uuid, 'outcome' => 'cancelled',
        ])->assertOk();
        if ($case === 'missing') {
            $order->update(['charge_device_id' => null]);
        } else {
            $this->till->update(['branch_id' => $case === 'unassigned' ? null : 20]);
        }
        $before = $this->snapshot();
        $this->assertSame([], app(ListStationQrAwaitingOrdersAction::class)->handle($this->station));
        $this->postAs($this->station, '/api/v1/device/qr/claim-charge', ['order_uuid' => $order->uuid])
            ->assertConflict()->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_lapsed_attended_payment_evidence_is_never_cleared_by_cancellation(): void
    {
        $order = $this->order();
        $this->claim($order)->assertOk();
        $order->update(['charge_outcome' => 'lapsed']);
        $before = $this->snapshot();
        $this->postAs($this->till, '/api/v1/device/qr/release-charge', [
            'order_uuid' => $order->uuid, 'outcome' => 'cancelled',
        ])->assertConflict();
        $this->claim($order)->assertConflict()->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');
        $this->assertSame([], app(ListStationQrAwaitingOrdersAction::class)->handle($this->station));
        $this->assertSame($before, $this->snapshot());
    }
}
