<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\ListDineInQrTableBoardAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ClearEmptyTableSessionAction;
use App\Actions\Tables\ListTableBoardAction;
use App\Models\QrSession;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class ClearEmptyTableSessionTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    public static function cases(): array
    {
        $cases = [];
        foreach (['fixed_pos', 'handheld', 'payment_station'] as $type) {
            foreach (['pending', 'active', 'expired'] as $status) {
                $cases[$type.' '.$status] = [$type, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_empty_session_can_be_cleared_and_reopened(string $type, string $status): void
    {
        $device = $this->seatingDevice($type);
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $opened = app(OpenDineInTableAction::class)->handle($station, $table->id);
        $session = $opened['session'];
        $session->update(['status' => $status]);
        $seat = TableSession::findOrFail($session->table_session_id);
        $this->withToken((string) $device->plainTextToken)->postJson('/api/v1/device/tables/clear-empty-session', [
            'table_id' => $table->id, 'seating_uuid' => $seat->uuid,
        ])->assertOk()->assertJsonPath('data.status', 'cleared');
        $this->assertSame('closed', $seat->fresh()->status);
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertNull(app(BindQrTableSessionAction::class)->handle($opened['table_token'], 'old-phone'));
        $this->assertNull(app(ListTableBoardAction::class)->handle($device)[0]['seating']);
        $next = app(OpenDineInTableAction::class)->handle($station, $table->id);
        $this->assertNotSame($session->id, $next['session']->id);
        try {
            app(ClearEmptyTableSessionAction::class)->handle($device, $table->id, $seat->uuid);
            $this->fail('A stale clear affected a new session');
        } catch (QrDineInException $e) {
            $this->assertSame('table_session_changed', $e->codeName);
            $this->assertSame('pending', $next['session']->fresh()->status);
        }
    }

    public static function contents(): array
    {
        return array_map(fn ($kind) => [$kind], ['open', 'held', 'awaiting_payment', 'paid', 'void', 'pending_round', 'accepted_round', 'rejected_round', 'joined']);
    }

    #[DataProvider('contents')]
    public function test_nonempty_or_joined_session_is_never_cleared(string $kind): void
    {
        $device = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $opened = app(OpenDineInTableAction::class)->handle($device, $table->id);
        $seat = TableSession::findOrFail($opened['session']->table_session_id);
        if (str_ends_with($kind, '_round')) {
            $this->seatingRound($seat, attributes: ['status' => $kind === 'pending_round' ? 'pending_confirmation' : str_replace('_round', '', $kind)]);
        } elseif ($kind === 'joined') {
            $this->seatingRow($this->seatingTable('Other'), ['merged_into_id' => $seat->id]);
        } else {
            $this->seatingOrder($seat, ['status' => $kind]);
        }
        $this->withToken((string) $device->plainTextToken)->postJson('/api/v1/device/tables/clear-empty-session', [
            'table_id' => $table->id, 'seating_uuid' => $seat->uuid,
        ])->assertStatus(409);
        $this->assertSame('open', $seat->fresh()->status);
        $this->assertSame('pending', $opened['session']->fresh()->status);
    }

    public function test_clearing_latest_session_does_not_resurrect_old_expired_board_row(): void
    {
        $device = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $old = app(OpenDineInTableAction::class)->handle($device, $table->id)['session'];
        $old->update(['status' => QrSession::STATUS_EXPIRED]);
        $current = app(OpenDineInTableAction::class)->handle($device, $table->id)['session'];
        $seat = TableSession::findOrFail($current->table_session_id);
        app(ClearEmptyTableSessionAction::class)->handle($device, $table->id, $seat->uuid);
        $this->assertSame([], app(ListDineInQrTableBoardAction::class)->handle($device));
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame('closed', $current->fresh()->status);
    }

    public function test_wrong_branch_uuid_and_device_are_refused(): void
    {
        $device = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $opened = app(OpenDineInTableAction::class)->handle($device, $table->id);
        $seat = TableSession::findOrFail($opened['session']->table_session_id);
        $foreign = $this->seatingDevice('handheld', 20);
        $this->withToken((string) $foreign->plainTextToken)->postJson('/api/v1/device/tables/clear-empty-session', [
            'table_id' => $table->id, 'seating_uuid' => $seat->uuid,
        ])->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken((string) $device->plainTextToken)->postJson('/api/v1/device/tables/clear-empty-session', [
            'table_id' => $table->id, 'seating_uuid' => (string) Str::uuid(),
        ])->assertStatus(409);
        $this->withToken((string) $device->plainTextToken)->postJson('/api/v1/device/tables/clear-empty-session', [
            'table_id' => $table->id,
        ])->assertUnprocessable();
        $this->assertSame('open', $seat->fresh()->status);
        $this->assertSame(1, QrSession::count());
    }
}
