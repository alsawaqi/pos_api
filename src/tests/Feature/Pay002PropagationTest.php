<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Device\ResolveDeviceSoftPos;
use App\Actions\Device\SnapshotCardSoftPos;
use App\Actions\Qr\ClaimQrChargeAction;
use App\Actions\Qr\QrChargeException;
use App\Models\Device;
use App\Models\DeviceActivationToken;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Pay002PropagationTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $provider = 'mosambee_dhofar', bool $active = true): Device
    {
        DB::table('banks')->insert(['id' => 901, 'name' => 'Synthetic acquirer']);
        if ($provider !== 'missing') {
            DB::table('pos_bank_softpos_profiles')->insert([
                'bank_id' => 901, 'softpos_provider' => $provider,
                'softpos_package' => $provider === 'mosambee_dhofar' ? 'com.mosambee.dhofar.softpos' : 'com.mosambee.muscat.softpos',
                'is_active' => $active, 'currency_code' => '0512',
                'refund_needs_transaction_id' => $provider === 'mosambee_dhofar',
                'void_needs_session_id' => $provider === 'mosambee_muscat',
            ]);
        }

        return Device::factory()->paired('mdev_pay002')->create([
            'bank_id' => 901, 'terminal_id' => 'T901', 'terminal_pin' => '4821',
            'company_id' => 100, 'branch_id' => 10, 'device_type' => 'fixed_pos',
        ]);
    }

    public static function profiles(): array
    {
        return [
            'Dhofar legacy' => ['mosambee_dhofar', true, false, 'mosambee_dhofar', null],
            'Dhofar capable' => ['mosambee_dhofar', true, true, 'mosambee_dhofar', null],
            'Muscat legacy' => ['mosambee_muscat', true, false, 'mosambee_muscat', 'softpos_app_update_required'],
            'Muscat capable' => ['mosambee_muscat', true, true, 'mosambee_muscat', null],
            'none legacy' => ['none', true, false, null, 'softpos_app_update_required'],
            'none capable' => ['none', true, true, null, 'softpos_not_configured'],
            'missing capable' => ['missing', true, true, null, 'softpos_not_configured'],
            'inactive capable' => ['mosambee_dhofar', false, true, null, 'softpos_not_configured'],
        ];
    }

    #[DataProvider('profiles')]
    public function test_config_delta_and_activation_share_the_profile_and_credential_gate(string $provider, bool $active, bool $capable, ?string $resolved, ?string $reason): void
    {
        $device = $this->device($provider, $active);
        if ($capable) {
            $this->withHeader('X-Mithqal-SoftPos-Capable', '1');
        }
        foreach (['/api/v1/device/config', '/api/v1/device/config/delta?since='.urlencode(now()->subMinute()->toIso8601String())] as $url) {
            $this->withToken('mdev_pay002')->getJson($url)->assertOk()
                ->assertJsonPath('meta.softpos.provider', $resolved)
                ->assertJsonPath('meta.softpos.blocked_reason', $reason)
                ->assertJsonPath('meta.softpos.requires_manual_first_launch', true)
                ->assertJsonPath('meta.softpos.login_requires_approved_code', true)
                ->assertJsonPath('meta.terminal_id', $reason === null ? 'T901' : null)
                ->assertJsonPath('meta.terminal_pin', $reason === null ? '4821' : null);
        }
        DeviceActivationToken::factory()->for($device)->forPlaintext('pay002_activate')->create();
        $this->postJson('/api/v1/auth/device/activate', ['code' => 'pay002_activate', 'serial' => $device->serial_number])->assertOk()
            ->assertJsonPath('data.device.softpos.provider', $resolved)
            ->assertJsonPath('data.device.softpos.blocked_reason', $reason)
            ->assertJsonPath('data.device.terminal_id', $reason === null ? 'T901' : null);
        $this->assertSame($resolved, app(ResolveDeviceSoftPos::class)->handle($device)?->provider);
    }

    public function test_delta_resolves_a_changed_profile_even_with_no_catalogue_changes(): void
    {
        $this->device();
        $this->withHeader('X-Mithqal-SoftPos-Capable', '1')->withToken('mdev_pay002');
        $this->getJson('/api/v1/device/config')->assertJsonPath('meta.softpos.provider', 'mosambee_dhofar');
        DB::table('pos_bank_softpos_profiles')->where('bank_id', 901)->update([
            'softpos_provider' => 'mosambee_muscat', 'softpos_package' => 'com.mosambee.muscat.softpos',
            'refund_needs_transaction_id' => false, 'void_needs_session_id' => true,
        ]);
        $this->getJson('/api/v1/device/config/delta?since='.urlencode(now()->subMinute()->toIso8601String()))
            ->assertOk()->assertJsonPath('meta.softpos.provider', 'mosambee_muscat')
            ->assertJsonPath('meta.softpos.void_needs_session_id', true)
            ->assertJsonPath('meta.softpos.refund_needs_transaction_id', false);
    }

    public static function tenders(): array
    {
        return [
            'matching Dhofar' => ['mosambee_dhofar', 'mosambee_dhofar', false, null],
            'matching Muscat' => ['mosambee_muscat', 'mosambee_muscat', false, null],
            'wrong provider' => ['mosambee_dhofar', 'mosambee_muscat', false, 'reported_provider_differs_from_bank_profile'],
            'no configured provider' => ['none', 'mosambee_muscat', false, 'reported_provider_differs_from_bank_profile'],
            'old Muscat' => ['mosambee_muscat', null, false, 'softpos_app_update_required'],
            'already blocked' => ['mosambee_dhofar', 'mosambee_dhofar', true, 'device_blocked'],
        ];
    }

    #[DataProvider('tenders')]
    public function test_tenders_snapshot_receipts_and_record_mismatches_once(string $provider, ?string $reported, bool $blocked, ?string $note): void
    {
        $device = $this->device($provider);
        if ($blocked) {
            $device->forceFill(['card_tenders_blocked_reason' => 'softpos_mismatch', 'card_tenders_blocked_at' => now()])->save();
        }
        $order = Order::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $device->id, 'order_type' => 'quick', 'source' => 'main_pos', 'status' => 'open',
            'subtotal' => 5, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 5, 'opened_at' => now(),
        ]);
        $receipt = [
            'transactionId' => 'TX-901', 'retrievalReferenceNumber' => 'RRN-901',
            'batchNumber' => '000007', 'cardNumber' => '123456******1234', 'cardType' => 'VISA',
            'date' => '13/09/2026', 'time' => '23:59:01',
        ];
        $event = [
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $order->uuid, 'payments' => [[
                'method' => 'card', 'amount_baisas' => 5000, 'softpos_provider' => $reported,
                'softpos_package' => 'untrusted.package', 'bank_response' => ['receiptResponse' => json_encode($receipt)],
            ]]],
        ];
        $response = $this->withToken('mdev_pay002')->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        if ($note !== null) {
            $response->assertJsonPath('data.results.0.result.softpos_mismatch', true);
            $this->assertSame('softpos_mismatch', $device->fresh()->card_tenders_blocked_reason);
        }
        $payment = Payment::sole();
        $this->assertSame($provider === 'none' ? null : $provider, $payment->softpos_provider);
        $this->assertNotSame('untrusted.package', $payment->softpos_package);
        $this->assertSame($reported, $payment->softpos_reported_provider);
        $this->assertSame($note, $payment->softpos_mismatch_note);
        $this->assertSame($note !== null, $payment->softpos_mismatch);
        $this->assertSame('TX-901', $payment->softpos_transaction_id);
        $this->assertSame('RRN-901', $payment->softpos_rrn);
        $this->assertSame('000007', $payment->softpos_batch_number);
        $this->assertSame('123456******1234', $payment->softpos_card_masked);
        $this->assertSame('VISA', $payment->softpos_card_type);
        $this->assertSame('2026-09-13 19:59:01', $payment->softpos_receipt_at->format('Y-m-d H:i:s'));
        $this->assertSame('5.000', $payment->amount);
        $this->postJson('/api/v1/device/sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.duplicate', true);
        $this->assertSame(1, Payment::count());
    }

    public function test_receipt_missing_invalid_and_unmasked_fields_are_not_failures(): void
    {
        $action = app(SnapshotCardSoftPos::class);
        $this->assertSame(array_fill_keys([
            'softpos_transaction_id', 'softpos_rrn', 'softpos_batch_number', 'softpos_card_masked',
            'softpos_card_type', 'softpos_receipt_at',
        ], null), $action->receipt([]));
        foreach (['1234567890123456', '1234567****1234', '123456****12345'] as $pan) {
            $result = $action->receipt(['receiptResponse' => ['cardNumber' => $pan, 'date' => '31/02/2026', 'time' => '12:00:00']]);
            $this->assertNull($result['softpos_card_masked']);
            $this->assertNull($result['softpos_receipt_at']);
        }
        $result = $action->receipt(['receiptResponse' => ['cardNumber' => '******1234', 'date' => '2026-09-13', 'time' => '09:12:13']]);
        $this->assertSame('******1234', $result['softpos_card_masked']);
        $this->assertSame('2026-09-13 05:12:13', $result['softpos_receipt_at']->format('Y-m-d H:i:s'));
    }

    public function test_blocked_station_claim_is_refused_without_writes(): void
    {
        $device = $this->device();
        $device->forceFill(['device_type' => 'payment_station', 'card_tenders_blocked_reason' => 'softpos_mismatch'])->save();
        $order = Order::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'order_type' => 'quick', 'source' => 'qr_web', 'status' => 'awaiting_payment',
            'subtotal' => 5, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 5, 'opened_at' => now(),
        ]);
        $before = $order->fresh()->getAttributes();
        try {
            app(ClaimQrChargeAction::class)->handle($device, ['order_uuid' => $order->uuid]);
            $this->fail('Expected a blocked station refusal');
        } catch (QrChargeException $exception) {
            $this->assertSame('softpos_blocked', $exception->codeName);
        }
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(0, Payment::count());
    }
}
