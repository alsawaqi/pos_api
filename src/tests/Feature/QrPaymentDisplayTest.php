<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\PublicQr\QrStatusController;
use App\Models\Floor;
use App\Models\QrSession;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QrPendingTestCase;

final class QrPaymentDisplayTest extends QrPendingTestCase
{
    public static function cases(): iterable
    {
        foreach (['quick', 'dine_in'] as $kind) {
            foreach (['fixed_pos', 'handheld', 'payment_station'] as $type) {
                foreach (['live_claim', 'expired_claim', 'uncertain', 'approved', 'lapsed', 'cancelled', 'declined'] as $claim) {
                    $expected = match ($claim) {
                        'live_claim' => $type === 'payment_station' ? 'station_processing' : 'staff_processing',
                        'cancelled', 'declined' => $type === 'payment_station' ? 'awaiting_station' : 'awaiting_counter',
                        default => 'recovery_required',
                    };
                    yield "$kind/$type/$claim" => [$kind, $type, $claim, $expected];
                }
            }
            foreach (['missing', 'other_branch', 'other_company', 'inactive', 'unknown_type', 'partial', 'deadline_now', 'future_claim'] as $case) {
                yield "$kind/$case" => [$kind, $case, 'live_claim', 'recovery_required'];
            }
            yield "$kind/never_claimed" => [$kind, 'fixed_pos', 'none', 'awaiting_station'];
            yield "$kind/partial_residue" => [$kind, 'fixed_pos', 'residue', 'recovery_required'];
        }
    }

    #[DataProvider('cases')]
    public function test_display_is_tenant_scoped_read_only_and_never_exposes_claim_details(string $kind, string $type, string $claim, string $expected): void
    {
        $holder = in_array($type, ['fixed_pos', 'handheld', 'payment_station'], true)
            ? $this->device($type) : $this->device('payment_station');
        $attributes = $this->charge($claim);
        if (! in_array($claim, ['none', 'residue'], true)) {
            $attributes['charge_device_id'] = $type === 'missing' ? null : $holder->id;
        }
        if ($type === 'other_branch') {
            $holder->forceFill(['branch_id' => 20])->save();
        } elseif ($type === 'other_company') {
            $holder->forceFill(['company_id' => 200])->save();
        } elseif ($type === 'inactive') {
            $holder->update(['status' => 'inactive']);
        } elseif ($type === 'unknown_type') {
            $holder->forceFill(['device_type' => 'kitchen'])->save();
        } elseif ($type === 'partial') {
            $attributes['charge_amount_baisas'] = null;
        } elseif ($type === 'deadline_now') {
            $attributes['charge_deadline_at'] = now();
        } elseif ($type === 'future_claim') {
            $attributes['charge_claimed_at'] = now()->addMinute();
        }
        $order = $this->order($attributes + ['status' => 'awaiting_payment', 'order_type' => $kind]);
        $session = QrSession::findOrFail($order->qr_session_id);
        if ($kind === 'dine_in') {
            $floor = Floor::create(['uuid' => (string) Str::uuid(), 'company_id' => 100,
                'branch_id' => 10, 'name' => 'Synthetic floor', 'status' => 'active']);
            $table = Table::create(['uuid' => (string) Str::uuid(), 'company_id' => 100,
                'floor_id' => $floor->id, 'label' => 'T1', 'seats' => 4, 'shape' => 'square',
                'status' => 'active', 'qr_token' => hash('sha256', 'synthetic-display-table')]);
            $session->update(['table_id' => $table->id]);
            $order->update(['table_id' => $table->id]);
        }
        $before = $this->snapshot();
        $request = Request::create('/api/v1/public/qr/status');
        $request->attributes->set('qr_session', $session);
        $response = app(QrStatusController::class)($request);
        $this->assertSame(200, $response->status());
        $data = $response->getData(true)['data'];
        $this->assertSame($expected, $data['payment_display']);
        $this->assertSame('awaiting_payment', $data['order']['status']);
        $this->assertSame(4750, $data['order']['grand_total_baisas']);
        $this->assertSame(['uuid', 'status', 'receipt_number', 'temp_reference', 'subtotal_baisas',
            'discount_total_baisas', 'tax_total_baisas', 'grand_total_baisas'], array_keys($data['order']));
        foreach (['charge_device_id', 'charge_claimed_at', 'charge_deadline_at', 'client_secret', 'device_token', 'customer_id', 'phone'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'":', $response->getContent());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_public_route_delivers_hint_but_wrong_secret_still_has_no_order(): void
    {
        $order = $this->order($this->charge('live_claim') + ['status' => 'awaiting_payment']);
        $session = QrSession::findOrFail($order->qr_session_id);
        $session->update(['client_secret_hash' => QrSession::hashClientSecret('synthetic-display-secret')]);
        $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => 'synthetic-display-secret'])
            ->getJson('/api/v1/public/qr/status')->assertOk()
            ->assertJsonPath('data.payment_display', 'station_processing');
        $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => 'wrong-secret'])
            ->getJson('/api/v1/public/qr/status')->assertNotFound()->assertJsonPath('data', null);
    }
}
