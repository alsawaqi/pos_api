<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\SyncEvent;
use App\Support\Pricing\WireValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

final class DeviceSyncPricingValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
        DB::table('pos_products')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Latte',
            'base_price' => 1.000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_flagged_order_is_checked_in_the_ledger_and_ack(): void
    {
        $this->device('validator-main');
        $event = $this->event('order.create', $this->order());

        $response = $this->push('validator-main', [$event])->assertOk();
        $pricing = $response->json('data.results.0.result.pricing_check');

        $this->assertSame([
            'checked' => true,
            'engine' => 'php-mithqal/0.2.0',
            'match' => true,
            'failures' => [],
        ], $pricing);
        $this->assertSame('processed', $response->json('data.results.0.status'));

        $ledger = SyncEvent::query()->where('client_event_id', $event['client_event_id'])->firstOrFail();
        $this->assertSame($pricing, $ledger->result_json['pricing_check']);
    }

    public function test_one_baisa_mismatch_warns_but_processes_and_preserves_device_money(): void
    {
        $this->device('validator-mismatch');
        Log::spy();
        $uuid = (string) Str::uuid();
        $event = $this->event('order.create', $this->order([
            'uuid' => $uuid,
            'grand_total_baisas' => 2001,
        ]));

        $response = $this->push('validator-mismatch', [$event])->assertOk();

        $this->assertSame('processed', $response->json('data.results.0.status'));
        $this->assertFalse($response->json('data.results.0.result.pricing_check.match'));
        $this->assertSame(
            'identity_zero',
            $response->json('data.results.0.result.pricing_check.failures.0.code'),
        );
        $this->assertSame('2.001', Order::query()->where('uuid', $uuid)->value('grand_total'));
        $this->assertDatabaseHas('pos_sync_events', [
            'client_event_id' => $event['client_event_id'],
            'ack_status' => SyncEvent::STATUS_PROCESSED,
        ]);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $message === 'pricing mismatch'
                && $context['order_uuid'] === $uuid
                && $context['company_id'] === 100
                && $context['branch_id'] === 10
                && $context['source'] === 'handheld'
                && $context['failure_codes'] === ['identity_zero']
                && $context['deltas'] === [['code' => 'identity_zero', 'delta' => 1]],
        );
    }

    public function test_setting_off_returns_the_exact_unchecked_shape(): void
    {
        $this->device('validator-off');
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'pricing_enforcement',
            'value' => json_encode('off'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->push('validator-off', [
            $this->event('order.create', $this->order()),
        ])->assertOk();

        $this->assertSame(
            ['checked' => false, 'reason' => 'off'],
            $response->json('data.results.0.result.pricing_check'),
        );
    }

    public function test_unflagged_legacy_shape_returns_no_flag_without_warning(): void
    {
        $this->device('validator-legacy');
        Log::spy();
        $order = $this->order([
            'discount_total_baisas' => 500,
            'grand_total_baisas' => 1500,
        ]);
        unset($order['pricing_engine'], $order['discounts'], $order['comps'], $order['comp_total_baisas']);

        $response = $this->push('validator-legacy', [
            $this->event('order.create', $order),
        ])->assertOk();

        $this->assertSame('processed', $response->json('data.results.0.status'));
        $this->assertSame(
            ['checked' => false, 'reason' => 'no_flag'],
            $response->json('data.results.0.result.pricing_check'),
        );
        Log::shouldNotHaveReceived('warning');
    }

    public function test_container_resolution_error_is_fail_open_and_order_still_processes(): void
    {
        $this->device('validator-error');
        Log::spy();
        $this->app->bind(
            WireValidator::class,
            static fn (): WireValidator => throw new RuntimeException('forced validator resolution failure'),
        );
        $uuid = (string) Str::uuid();

        $response = $this->push('validator-error', [
            $this->event('order.create', $this->order(['uuid' => $uuid])),
        ])->assertOk();

        $this->assertSame('processed', $response->json('data.results.0.status'));
        $this->assertSame(
            ['checked' => false, 'reason' => 'error'],
            $response->json('data.results.0.result.pricing_check'),
        );
        $this->assertDatabaseHas('pos_orders', ['uuid' => $uuid, 'grand_total' => 2.000]);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => $message === 'pricing validator error'
                && $context['order_uuid'] === $uuid
                && $context['exception'] === RuntimeException::class,
        );
    }

    public function test_hold_and_transfer_results_never_gain_pricing_check(): void
    {
        $source = $this->device('validator-source', name: 'Source');
        $target = $this->device('validator-target', name: 'Target', type: 'handheld');
        $hold = $this->event('order.hold', $this->order(['uuid' => (string) Str::uuid()]));
        $transfer = $this->event(
            'order.transfer',
            $this->order(['uuid' => (string) Str::uuid()]),
            ['target_device_id' => (int) $target->getKey()],
        );

        $response = $this->push((string) $source->device_token, [$hold, $transfer])->assertOk();

        $this->assertSame('processed', $response->json('data.results.0.status'));
        $this->assertSame('processed', $response->json('data.results.1.status'));
        $this->assertArrayNotHasKey('pricing_check', $response->json('data.results.0.result'));
        $this->assertArrayNotHasKey('pricing_check', $response->json('data.results.1.result'));
    }

    private function device(
        string $token,
        string $name = 'POS',
        string $type = 'pos_terminal',
    ): Device {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => 10,
            'name' => $name,
            'device_type' => $type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function order(array $overrides = []): array
    {
        return array_replace([
            'pricing_engine' => 1,
            'uuid' => (string) Str::uuid(),
            'order_type' => 'quick',
            'source' => 'handheld',
            'staff_id' => 7,
            'opened_at' => now()->subHour()->toIso8601String(),
            'subtotal_baisas' => 2000,
            'discount_total_baisas' => 0,
            'comp_total_baisas' => 0,
            'tax_total_baisas' => 0,
            'grand_total_baisas' => 2000,
            'lines' => [[
                'product_id' => 1,
                'qty' => 2,
                'unit_price_baisas' => 1000,
                'line_total_baisas' => 2000,
            ]],
            'discounts' => [],
            'comps' => [],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payloadExtra
     * @return array<string, mixed>
     */
    private function event(string $eventType, array $order, array $payloadExtra = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'client_timestamp' => now()->toIso8601String(),
            'payload' => $payloadExtra + ['order' => $order],
        ];
    }

    /** @param list<array<string, mixed>> $events */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }
}
