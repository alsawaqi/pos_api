<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\ConfirmStaffRoundAction;
use App\Http\Controllers\Api\V1\PublicQr\QrStatusController;
use App\Http\Controllers\Api\V1\PublicQr\QrTableRoundController;
use App\Http\Requests\Api\V1\PublicQr\SubmitDineInQrRoundRequest;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class RoundItemOwnershipTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'UTC'));
    }

    public static function confirmationModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('confirmationModes')]
    public function test_customer_direct_and_confirmed_rounds_stamp_real_items_and_strip_private_ownership_from_both_public_presenters(bool $confirm): void
    {
        $station = $this->seatingDevice('payment_station');
        $till = $this->seatingDevice();
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $other = $this->seatingProduct();
        if ($confirm) {
            DB::table('pos_branch_settings')->insert([
                'company_id' => 100, 'branch_id' => 10, 'key' => 'dine_in_round_mode',
                'value' => json_encode('staff_confirm'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $opened = app(OpenDineInTableAction::class)->handle($station, (int) $table->id);
        $session = app(BindQrTableSessionAction::class)->handle($opened['table_token'], 'ownership-secret');
        $this->assertNotNull($session);
        $payload = [
            'client_request_id' => 'ownership-customer', 'phone' => '92001234', 'plate_number' => null,
            'lines' => [
                ['product_id' => (int) $product->id, 'qty' => 2, 'addon_ids' => [], 'notes' => 'first'],
                ['product_id' => (int) $other->id, 'qty' => 1, 'addon_ids' => [], 'notes' => 'second'],
            ],
        ];
        $result = app(SubmitDineInQrRoundAction::class)->handle((int) $session->id, $payload, '127.0.0.1');
        $round = $result['round'];
        if ($confirm) {
            foreach ($round->priced_lines as $line) {
                $this->assertArrayNotHasKey('order_item_id', $line);
            }
            $this->assertDatabaseCount('pos_order_items', 0);
            app(ConfirmDineInQrRoundAction::class)->handle($till, (int) $round->id);
        }
        $round->refresh();
        $this->assertOwned($round);
        $lines = $round->priced_lines;
        $lines[0]['cancelled_qty'] = 1;
        $lines[0]['cancellations'] = [['client_request_id' => 'private-audit', 'qty' => 1, 'discount_baisas' => 0, 'at' => now()->toIso8601String()]];
        $round->update(['priced_lines' => $lines]);
        $expectedPublic = array_map(static function (array $line): array {
            unset($line['order_item_id'], $line['cancellations']);

            return $line;
        }, $lines);
        $request = Request::create('/api/v1/public/qr/status');
        $request->attributes->set('qr_session', $session->fresh());
        $status = app(QrStatusController::class)($request)->getData(true);
        $this->assertSame($expectedPublic, $status['data']['dine_in']['rounds'][0]['priced_lines']);
        $submission = SubmitDineInQrRoundRequest::create('/api/v1/public/qr/rounds', 'POST', $payload);
        $submission->attributes->set('qr_session', $session->fresh());
        $submission->setContainer($this->app);
        $submission->setValidator(Validator::make($payload, $submission->rules()));
        $response = app(QrTableRoundController::class)($submission)->getData(true);
        $this->assertTrue($response['data']['replayed']);
        $this->assertSame($expectedPublic, $response['data']['round']['priced_lines']);
        $this->assertSame($lines, $round->fresh()->priced_lines);
    }

    #[DataProvider('confirmationModes')]
    public function test_staff_direct_and_partial_hold_confirmation_map_nonheld_positions_only(bool $hold): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $other = $this->seatingProduct();
        $unavailable = $this->seatingProduct();
        $unavailable->update(['status' => 'inactive']);
        $lines = [
            ['product_id' => (int) $product->id, 'qty' => 2, 'addon_ids' => [], 'notes' => 'first'],
            ['product_id' => (int) $other->id, 'qty' => 1, 'addon_ids' => [], 'notes' => 'second'],
        ];
        if ($hold) {
            array_splice($lines, 1, 0, [[
                'product_id' => (int) $unavailable->id, 'qty' => 1, 'addon_ids' => [], 'notes' => 'held',
            ]]);
        }
        $ack = app(AppendStaffRoundAction::class)->handle($device, [
            'seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id,
            'queued_offline' => false, 'client_request_id' => 'ownership-staff',
            'submitted_at' => now()->toIso8601String(), 'lines' => $lines,
        ], now(), now());
        $this->assertSame($hold ? 'held' : 'appended', $ack['outcome']);
        $round = QrOrderRound::findOrFail($ack['round_id']);
        if ($hold) {
            foreach ($round->priced_lines as $line) {
                $this->assertArrayNotHasKey('order_item_id', $line);
            }
            $this->assertDatabaseCount('pos_order_items', 0);
            app(ConfirmStaffRoundAction::class)->handle($device, $seating->uuid, (int) $round->id);
        }
        $this->assertOwned($round->fresh());
    }

    private function assertOwned(QrOrderRound $round): void
    {
        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $round->status);
        $items = OrderItem::query()->where('order_id', $round->order_id)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $position = 0;
        foreach ($round->priced_lines as $line) {
            if (isset($line['held_reason'])) {
                $this->assertArrayNotHasKey('order_item_id', $line);
                $this->assertSame('dropped_at_review', $line['held_disposition']);

                continue;
            }
            $item = $items[$position++];
            $this->assertSame((int) $item->id, $line['order_item_id']);
            $this->assertSame((int) $item->product_id, $line['product_id']);
            $this->assertSame((int) $item->qty, $line['qty']);
            $this->assertSame($item->notes, $line['notes']);
        }
        $this->assertSame(2, $position);
    }
}
