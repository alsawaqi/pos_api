<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\OpenDineInTableAction;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class QrFlowBoundaryRegressionTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    public static function bindingStates(): array
    {
        return ['pending' => [false], 'same-secret active replay' => [true]];
    }

    #[DataProvider('bindingStates')]
    public function test_f01_quick_bind_refuses_table_tokens_without_mutating_them(bool $active): void
    {
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $session = app(OpenDineInTableAction::class)->handle($station, $table->id)['session'];
        $secret = 'f01-synthetic-client';
        if ($active) {
            $session->update([
                'status' => QrSession::STATUS_ACTIVE,
                'client_secret_hash' => QrSession::hashClientSecret($secret),
                'bound_at' => now(), 'last_seen_at' => now()->subMinute(),
            ]);
        }
        $before = $session->fresh()->getAttributes();
        $this->postJson('/api/v1/public/qr/bind', [
            'token' => $session->token, 'client_secret' => $secret,
        ])->assertNotFound()->assertJsonPath('errors.0.code', 'qr_bind_failed');
        $this->assertSame($before, $session->fresh()->getAttributes());
    }

    public static function unpaidOrders(): array
    {
        $cases = [];
        foreach (['fixed_pos', 'handheld', 'payment_station'] as $type) {
            foreach (['open', 'held', 'awaiting_payment'] as $status) {
                $cases[$type.' / '.$status] = [$type, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('unpaidOrders')]
    public function test_f01_clear_empty_refuses_unpaid_order_linked_only_by_qr_session(string $type, string $status): void
    {
        $station = $this->seatingDevice('payment_station');
        $reader = $this->seatingDevice($type);
        $table = $this->seatingTable();
        $session = app(OpenDineInTableAction::class)->handle($station, $table->id)['session'];
        $seat = TableSession::findOrFail($session->table_session_id);
        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $station->id, 'qr_session_id' => $session->id,
            'table_id' => null, 'table_session_id' => null, 'order_type' => 'quick',
            'status' => $status, 'source' => 'qr_web', 'temp_reference' => 'F01-SYNTHETIC',
            'subtotal' => '1.000', 'grand_total' => '1.000', 'opened_at' => now(),
        ]);
        $this->withToken((string) $reader->device_token)->postJson('/api/v1/device/tables/clear-empty-session', [
            'table_id' => $table->id, 'seating_uuid' => $seat->uuid,
        ])->assertConflict()->assertJsonPath('errors.0.code', 'table_session_not_empty');
        $this->assertSame('open', $seat->fresh()->status);
        $this->assertSame('pending', $session->fresh()->status);
        $this->assertSame($status, $order->fresh()->status);
        $this->assertSame(0, $order->payments()->count());
    }
}
