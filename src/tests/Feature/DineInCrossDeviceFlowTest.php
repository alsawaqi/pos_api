<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Orders\VoidOrderCoreAction;
use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\ClearDineInQrTableAction;
use App\Actions\Qr\FinishDineInQrOrderAction;
use App\Actions\Qr\ListDineInQrTableBoardAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\OpenStaffTableSessionAction;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/** Cross-device table lifecycle regression scenarios, using SQLite memory. */
final class DineInCrossDeviceFlowTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    public static function devices(): array
    {
        return ['machine' => ['fixed_pos'], 'handheld' => ['handheld']];
    }

    public static function timings(): array
    {
        return [
            'machine / scanned before void' => ['fixed_pos', true],
            'machine / scanned after void' => ['fixed_pos', false],
            'handheld / scanned before void' => ['handheld', true],
            'handheld / scanned after void' => ['handheld', false],
        ];
    }

    private function flow(string $type, bool $stationFirst = false): array
    {
        $staff = $this->seatingDevice($type);
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $qr = $stationFirst ? app(OpenDineInTableAction::class)->handle($station, $table->id) : null;
        $payload = ['seating_key' => (string) Str::uuid(), 'table_id' => $table->id, 'queued_offline' => false];
        $opened = app(OpenStaffTableSessionAction::class)->handle($staff, $payload + [
            'opened_at' => now()->toIso8601String(), 'joined_table_ids' => [],
        ], now(), now());
        $round = app(AppendStaffRoundAction::class)->handle($staff, $payload + [
            'client_request_id' => 'staff-round', 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ], now(), now());
        $order = Order::where('uuid', $round['order_uuid'])->sole();
        $seating = TableSession::findOrFail($order->table_session_id);

        return compact('staff', 'station', 'table', 'product', 'order', 'seating', 'qr');
    }

    private function customerRound(array $f, QrSession $session): array
    {
        return app(SubmitDineInQrRoundAction::class)->handle($session->id, [
            'client_request_id' => 'customer-round', 'phone' => '92004321',
            'lines' => [['product_id' => $f['product']->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ], '127.0.0.1');
    }

    private function report(string $scenario, array $data): void
    {
        fwrite(STDOUT, "\nDINE_IN_FLOW ".json_encode(['scenario' => $scenario] + $data, JSON_THROW_ON_ERROR)."\n");
    }

    #[DataProvider('devices')]
    public function test_staff_bill_must_be_present_in_station_occupancy(string $type): void
    {
        $f = $this->flow($type);
        $rows = app(ListDineInQrTableBoardAction::class)->handle($f['station']);
        $this->report('staff-first occupancy', ['device' => $type, 'staff_bill' => $f['order']->status, 'total' => $f['order']->grand_total, 'station_rows' => $rows]);
        $this->assertNotEmpty($rows, 'Station omits an occupied staff table.');
        $this->assertSame($f['order']->uuid, $rows[0]['order']['uuid']);
    }

    #[DataProvider('timings')]
    public function test_void_before_customer_first_round_must_retire_attached_phone_credential(string $type, bool $bindBefore): void
    {
        $f = $this->flow($type);
        $opened = app(OpenDineInTableAction::class)->handle($f['station'], $f['table']->id);
        if ($bindBefore) {
            app(BindQrTableSessionAction::class)->handle($opened['table_token'], 'phone-secret');
        }
        app(VoidOrderCoreAction::class)->handle($f['order'], $f['staff'], now(), 'Disposable diagnostic');
        $afterVoid = $opened['session']->fresh()->status;
        $bound = app(BindQrTableSessionAction::class)->handle($opened['table_token'], 'phone-secret');
        $credential = $opened['session']->fresh();
        try {
            $this->customerRound($f, $credential);
            $roundResult = 'accepted';
        } catch (QrDineInException $e) {
            $roundResult = $e->codeName;
        }
        try {
            app(FinishDineInQrOrderAction::class)->handle($credential->id, 'counter');
            $finishResult = 'accepted';
        } catch (QrDineInException $e) {
            $finishResult = $e->codeName;
        }
        try {
            app(OpenDineInTableAction::class)->handle($f['station'], $f['table']->id);
            $openResult = 'opened';
        } catch (QrDineInException $e) {
            $openResult = $e->codeName;
        }
        $this->report('void before customer first round', [
            'device' => $type, 'scanned_before_void' => $bindBefore,
            'order_after_void' => $f['order']->fresh()->status, 'seating_after_void' => $f['seating']->fresh()->status,
            'credential_after_void' => $afterVoid, 'scan_returned_live_credential' => $bound !== null,
            'round_result' => $roundResult, 'finish_result' => $finishResult, 'station_reopen_result' => $openResult,
            'orders' => Order::count(), 'payments' => $f['order']->payments()->count(),
        ]);
        $this->assertSame('closed', $afterVoid, 'Attached credential survived staff bill closure.');
        $this->assertNull($bound);
        $this->assertSame('qr_round_session_not_active', $roundResult);
        $this->assertSame('opened', $openResult);
        $this->assertSame(0, $f['order']->payments()->count());
    }

    #[DataProvider('devices')]
    public function test_customer_first_round_adopts_existing_staff_bill_and_void_then_closes_both(string $type): void
    {
        $f = $this->flow($type);
        $opened = app(OpenDineInTableAction::class)->handle($f['station'], $f['table']->id);
        $session = app(BindQrTableSessionAction::class)->handle($opened['table_token'], 'phone-secret');
        $this->assertInstanceOf(QrSession::class, $session);
        $result = $this->customerRound($f, $session);
        $this->assertSame($f['order']->id, $result['order']->id);
        $this->assertSame(1, Order::count());
        $this->assertSame('2.000', $result['order']->grand_total);
        app(VoidOrderCoreAction::class)->handle($result['order'], $f['staff'], now(), 'Disposable diagnostic');
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('closed', $f['seating']->fresh()->status);
        $this->report('customer round before void', ['device' => $type, 'one_shared_bill' => true, 'credential_closed' => true]);
    }

    #[DataProvider('devices')]
    public function test_clear_unpaid_bill_is_refused(string $type): void
    {
        $f = $this->flow($type);
        try {
            app(ClearDineInQrTableAction::class)->handle($f['staff'], $f['table']->id);
            $this->fail('Unpaid table was cleared.');
        } catch (QrDineInException $e) {
            $this->assertSame('qr_table_unpaid_order', $e->codeName);
            $this->assertSame('open', $f['order']->fresh()->status);
            $this->report('clear unpaid bill', ['device' => $type, 'result' => $e->codeName]);
        }
    }

    #[DataProvider('devices')]
    public function test_station_first_staff_round_then_customer_round_share_one_bill(string $type): void
    {
        $f = $this->flow($type, true);
        $session = app(BindQrTableSessionAction::class)->handle($f['qr']['table_token'], 'phone-secret');
        $result = $this->customerRound($f, $session);
        $this->assertSame($f['order']->id, $result['order']->id);
        $this->assertSame(1, Order::count());
        $this->assertSame('2.000', $result['order']->grand_total);
        $this->report('station first then staff then customer', ['device' => $type, 'one_shared_bill' => true]);
    }
}
