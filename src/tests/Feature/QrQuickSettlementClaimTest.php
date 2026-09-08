<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QrPendingTestCase;

final class QrQuickSettlementClaimTest extends QrPendingTestCase
{
    private function table(): void
    {
        $floor = Floor::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Synthetic floor', 'display_order' => 1, 'status' => 'active',
        ]);
        Table::create([
            'id' => 999, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'floor_id' => $floor->id, 'label' => 'Synthetic table', 'seats' => 4,
            'shape' => 'square', 'qr_token' => hash('sha256', 'synthetic-table'),
            'status' => 'active', 'display_order' => 1,
        ]);
    }

    public static function sessions(): iterable
    {
        foreach (['live', 'timestamp_expired', 'explicit_expired', 'closed', 'missing'] as $session) {
            yield $session => [$session];
        }
    }

    #[DataProvider('sessions')]
    public function test_quick_first_claim_then_same_holder_replay(string $sessionCase): void
    {
        $order = $this->order([], $sessionCase);
        $beforeSession = $this->sessionRaw($order);
        $first = $this->claim($order)->assertOk()->assertJsonPath('data.charge_amount_baisas', 4750)
            ->assertJsonPath('data.already_claimed_by_this_device', false);
        $afterClaim = $this->sessionRaw($order);
        if ($sessionCase !== 'live') {
            $this->assertSame($beforeSession, $afterClaim);
        } else {
            $this->assertSame($beforeSession['expires_at'], $afterClaim['expires_at']);
            $this->assertSame('ordered', $afterClaim['status']);
        }
        $beforeReplay = $this->snapshot();
        $this->travel(1)->seconds();
        $replay = $this->claim($order)->assertOk()->assertJsonPath('data.already_claimed_by_this_device', true);
        $expected = $first->json('data');
        $expected['already_claimed_by_this_device'] = true;
        $this->assertSame($expected, $replay->json('data'));
        $this->assertSame($beforeReplay, $this->snapshot());
        $this->assertSame($afterClaim, $this->sessionRaw($order));
        if (getenv('QR_PENDING_EVIDENCE') === '1' && $sessionCase === 'timestamp_expired') {
            fwrite(STDOUT, "\nQUICK_CLAIM ".json_encode($first->json(), JSON_UNESCAPED_SLASHES)."\n");
            fwrite(STDOUT, 'QUICK_REPLAY '.json_encode($replay->json(), JSON_UNESCAPED_SLASHES)."\n");
            fwrite(STDOUT, "QUICK_SESSION_UNCHANGED true\n");
        }
    }

    public function test_expiry_between_claims_does_not_mutate_or_block_quick_replay(): void
    {
        $order = $this->order();
        $first = $this->claim($order)->assertOk();
        QrSession::whereKey($order->qr_session_id)->update(['expires_at' => now()->subSecond()]);
        $before = $this->snapshot();
        $replay = $this->claim($order)->assertOk()->assertJsonPath('data.already_claimed_by_this_device', true);
        $this->assertSame($first->json('data.charge_deadline_at'), $replay->json('data.charge_deadline_at'));
        $this->assertSame(4750, $replay->json('data.charge_amount_baisas'));
        $this->assertSame($before, $this->snapshot());
    }

    public static function replayRefusals(): iterable
    {
        yield 'another-till' => ['other', 'charge_already_claimed'];
        yield 'expired-claim' => ['expired', 'qr_charge_recovery_required'];
        yield 'uncertain' => ['uncertain', 'charge_already_claimed'];
        yield 'lapsed' => ['lapsed', 'qr_charge_recovery_required'];
        yield 'approved' => ['approved', 'charge_already_claimed'];
    }

    #[DataProvider('replayRefusals')]
    public function test_replay_refusal_preserves_all_rows(string $case, string $code): void
    {
        $order = $this->order([], 'timestamp_expired');
        $other = $this->device('fixed_pos');
        $this->claim($order)->assertOk();
        if ($case === 'expired') {
            $order->update(['charge_deadline_at' => now()->subSecond()]);
        } elseif ($case !== 'other') {
            $order->update(['charge_outcome' => $case]);
        }
        $before = $this->snapshot();
        $this->claim($order, $case === 'other' ? $other : null)->assertConflict()->assertJsonPath('errors.0.code', $code);
        $this->assertSame($before, $this->snapshot());
    }

    public static function residue(): iterable
    {
        foreach (['declined', 'cancelled', 'uncertain', 'approved', 'lapsed', 'residue'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('residue')]
    public function test_held_residue_cannot_start_a_claim(string $kind): void
    {
        $order = $this->order($this->charge($kind));
        $before = $this->snapshot();
        $this->claim($order)->assertConflict()->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_safe_cancelled_attempt_clears_then_claims_and_replays(): void
    {
        $order = $this->order([], 'timestamp_expired');
        $this->claim($order)->assertOk();
        $this->postAs($this->till, '/api/v1/device/qr/release-charge', [
            'order_uuid' => $order->uuid, 'outcome' => 'cancelled',
        ])->assertOk();
        $this->getPending()->assertJsonPath('data.orders.0.charge', 'cancelled')
            ->assertJsonPath('data.orders.0.actions.to_counter', true);
        $this->move($order)->assertOk()->assertJsonPath('data.actions.settle', true);
        $this->claim($order)->assertOk()->assertJsonPath('data.already_claimed_by_this_device', false);
        $this->claim($order)->assertOk()->assertJsonPath('data.already_claimed_by_this_device', true);
    }

    public function test_quick_wrong_session_link_and_unrouted_shapes_fail_closed(): void
    {
        $this->table();
        $order = $this->order();
        QrSession::whereKey($order->qr_session_id)->update(['table_id' => 999]);
        $before = $this->snapshot();
        $this->claim($order)->assertConflict()->assertJsonPath('errors.0.code', 'order_not_bound_to_device_session');
        $this->assertSame($before, $this->snapshot());
        foreach ([['status' => 'open'], ['temp_reference' => null], ['status' => 'awaiting_payment']] as $attributes) {
            $other = $this->order($attributes);
            $before = $this->snapshot();
            $this->claim($other)->assertConflict()->assertJsonPath('errors.0.code', 'qr_order_not_settleable');
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_station_is_refused_before_order_claim(): void
    {
        $order = $this->order();
        $before = $this->snapshot();
        $this->claim($order, $this->station)->assertConflict()->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_dine_in_replay_still_refuses_timestamp_expiry(): void
    {
        $this->table();
        $order = $this->order(['order_type' => 'dine_in', 'table_id' => 999]);
        QrSession::whereKey($order->qr_session_id)->update(['table_id' => 999]);
        QrOrderRound::create([
            'qr_session_id' => $order->qr_session_id, 'order_id' => $order->id, 'round_no' => 1,
            'status' => 'accepted', 'client_request_id' => (string) Str::uuid(),
            'priced_lines' => [['line_total_baisas' => 4750]], 'subtotal_baisas' => 4750,
            'tax_baisas' => 0, 'total_baisas' => 4750, 'submitted_at' => now(), 'resolved_at' => now(),
        ]);
        $this->claim($order)->assertOk();
        QrSession::whereKey($order->qr_session_id)->update(['expires_at' => now()->subSecond()]);
        $before = $this->snapshot();
        $this->claim($order)->assertConflict()->assertJsonPath('errors.0.code', 'qr_session_expired');
        $this->assertSame($before, $this->snapshot());
    }
}
