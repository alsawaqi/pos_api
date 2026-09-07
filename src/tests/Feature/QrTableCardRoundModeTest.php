<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\ReleaseTableCredentialAction;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

class QrTableCardRoundModeTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    public static function modes(): array
    {
        return [
            'card direct' => ['table_card', 'kitchen_direct', 'pending_confirmation'],
            'card confirm' => ['table_card', 'staff_confirm', 'pending_confirmation'],
            'station direct' => ['station', 'kitchen_direct', 'accepted'],
            'station confirm' => ['station', 'staff_confirm', 'pending_confirmation'],
        ];
    }

    #[DataProvider('modes')]
    public function test_card_always_requires_staff_while_station_keeps_branch_mode(string $origin, string $mode, string $expected): void
    {
        $table = $this->seatingTable();
        foreach (['dine_in_round_mode' => $mode, 'qr_table_card_enabled' => 'on'] as $key => $value) {
            DB::table('pos_branch_settings')->insert([
                'company_id' => 100, 'branch_id' => 10, 'key' => $key, 'value' => json_encode($value),
            ]);
        }
        if ($origin === 'station') {
            app(OpenDineInTableAction::class)->handle($this->seatingDevice('payment_station'), $table->id);
        }
        $bound = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'owner');
        $session = $bound instanceof QrSession ? $bound : QrSession::query()->where('uuid', $bound['session_uuid'])->sole();
        $result = app(SubmitDineInQrRoundAction::class)->handle($session->id, [
            'client_request_id' => 'round-mode', 'phone' => '91234567',
            'lines' => [['product_id' => $this->seatingProduct()->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ], '127.0.0.1');
        $this->assertSame($expected, $result['round']->status);
        $event = DB::table('pos_table_session_events')->where('event_type', $expected === 'accepted' ? 'round_appended' : 'round_pending')->sole();
        $payload = json_decode($event->payload, true);
        $this->assertSame($result['round']->id, $payload['round_id']);
        if ($expected === 'pending_confirmation') {
            $this->assertSame($session->origin, $payload['credential_origin']);
            $this->assertSame($session->scan_geofence_verdict, $payload['geofence']);
            $this->assertNotNull($result['round']->confirm_payload);
            $this->assertDatabaseCount('pos_order_items', 0);
        }
        $notice = json_decode(DB::table('pos_table_session_events')->where('event_type', 'customer_order_arrived')->sole()->payload, true);
        $this->assertSame(['table_session_uuid', 'order_uuid', 'round_id'], array_keys($notice));
        $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => 'owner'])
            ->getJson('/api/v1/public/qr/status')->assertOk()
            ->assertJsonPath('data.dine_in.credential.staff_confirm', $expected === 'pending_confirmation');
    }

    public function test_adopted_and_handed_over_bills_still_wait_for_staff_under_kitchen_direct(): void
    {
        $table = $this->seatingTable();
        $seating = $this->seatingRow($table);
        $bill = $this->seatingOrder($seating);
        $this->seatingRound($seating, $bill);
        DB::table('pos_branch_settings')->insert([
            'company_id' => 100, 'branch_id' => 10, 'key' => 'qr_table_card_enabled', 'value' => '"on"',
        ]);
        $product = $this->seatingProduct();
        $bind = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'owner');
        $old = QrSession::query()->where('uuid', $bind['session_uuid'])->sole();
        $payload = [
            'client_request_id' => 'adopted', 'phone' => '91234567',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ];
        $first = app(SubmitDineInQrRoundAction::class)->handle($old->id, $payload, '127.0.0.1');
        $this->assertSame('pending_confirmation', $first['round']->status);
        $this->assertSame(2, $first['round']->round_no);
        $this->assertSame($bill->id, $first['order']->id);
        app(ReleaseTableCredentialAction::class)->handle($this->seatingDevice(), $seating->uuid);
        $bind = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'new-owner');
        $current = QrSession::query()->where('uuid', $bind['session_uuid'])->sole();
        unset($payload['phone']);
        $payload['client_request_id'] = 'handed-over';
        $next = app(SubmitDineInQrRoundAction::class)->handle($current->id, $payload, '127.0.0.1');
        $this->assertSame('pending_confirmation', $next['round']->status);
        $this->assertSame(3, $next['round']->round_no);
        $this->assertSame($bill->id, $next['order']->id);
        $this->assertSame('1.000', $bill->fresh()->grand_total);
        $this->assertDatabaseCount('pos_orders', 1);
    }
}
