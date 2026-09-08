<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\MoveQuickQrOrderToCounterAction;
use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Actions\Qr\QrChargeException;
use App\Http\Controllers\Api\V1\Device\DeviceOrdersController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\QrPendingTestCase;

final class QrPendingOrdersTest extends QrPendingTestCase
{
    public static function combinations(): iterable
    {
        foreach (['held', 'awaiting_payment'] as $status) {
            foreach (['live', 'timestamp_expired', 'explicit_expired', 'closed', 'missing'] as $session) {
                foreach (['none', 'live_claim', 'expired_claim', 'uncertain', 'approved', 'declined', 'cancelled', 'lapsed', 'residue'] as $charge) {
                    yield "$status/$session/$charge" => [$status, $session, $charge];
                }
            }
        }
    }

    #[DataProvider('combinations')]
    public function test_list_classification_and_read_only_privacy(string $status, string $session, string $kind): void
    {
        $order = $this->order(['status' => $status] + $this->charge($kind), $session);
        $before = $this->snapshot();
        $response = $this->getPending()->assertOk()->assertJsonCount(1, 'data.orders');
        $row = $response->json('data.orders.0');
        $expected = match ($kind) {
            'live_claim' => $status === 'awaiting_payment' ? 'live_claim' : 'uncertain',
            'expired_claim', 'uncertain', 'approved', 'lapsed', 'residue' => 'uncertain',
            default => $kind,
        };
        $this->assertSame($expected, $row['charge']);
        $this->assertSame($status === 'held' ? 'counter' : 'machine', $row['route']);
        $this->assertSame(match ($session) {
            'timestamp_expired', 'explicit_expired' => 'expired', default => $session,
        }, $row['session']);
        $this->assertSame([
            'settle' => $status === 'held' && $expected === 'none',
            'to_counter' => ($status === 'awaiting_payment' && in_array($expected, ['none', 'declined', 'cancelled'], true))
                || ($status === 'held' && in_array($expected, ['declined', 'cancelled'], true)),
        ], $row['actions']);
        $this->assertSame(match ($expected) {
            'live_claim' => 'charge_already_claimed', 'uncertain' => 'charge_outcome_uncertain', default => null,
        }, $row['refusal_code']);
        $this->assertSame(812, $row['age_seconds']);
        $this->assertSame('5555', $row['phone_tail']);
        $this->assertArrayNotHasKey('phone', $row);
        $this->assertArrayNotHasKey('customer_phone', $row);
        $this->assertStringNotContainsString('synthetic-', $response->getContent());
        $this->assertArrayNotHasKey('client_secret_hash', $row);
        $legacy = (new ReflectionMethod(DeviceOrdersController::class, 'mapOrder'))->invoke(
            new DeviceOrdersController, $order->load(['items.addons', 'comps']),
        );
        $this->assertSame(json_decode(json_encode($legacy, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR), array_diff_key($row, array_flip([
            'route', 'session', 'charge', 'age_seconds', 'phone_tail', 'actions', 'refusal_code',
        ])));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_scope_order_limit_and_terminal_rows(): void
    {
        $first = $this->order(['opened_at' => now()->subDay()]);
        for ($index = 0; $index < 200; $index++) {
            $this->order(['opened_at' => now()->subSeconds(200 - $index)]);
        }
        foreach ([
            ['branch_id' => 20], ['company_id' => 200], ['order_type' => 'dine_in'],
            ['source' => 'main_pos'], ['status' => 'paid'], ['status' => 'void'], ['status' => 'open'],
        ] as $attributes) {
            $this->order($attributes + ['opened_at' => now()->subDays(2)]);
        }
        $response = $this->getPending()->assertOk()->assertJsonCount(200, 'data.orders')
            ->assertJsonPath('data.orders.0.uuid', $first->uuid)
            ->assertJsonPath('meta.money_unit', 'baisas');
        $times = array_column($response->json('data.orders'), 'opened_at');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertNotNull($response->json('meta.generated_at'));
    }

    public function test_device_gate_precedes_lookup_and_route_throttles_are_existing_names(): void
    {
        $this->getPending($this->station)->assertConflict()->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->postAs($this->station, '/api/v1/device/qr/pending-orders/not-present/to-counter')
            ->assertConflict()->assertJsonPath('errors.0.code', 'device_not_attended');
        $move = app(MoveQuickQrOrderToCounterAction::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $move->handle($this->station, 'not-present');
            $this->fail('Station must be refused.');
        } catch (QrChargeException $exception) {
            $this->assertSame('device_not_attended', $exception->codeName);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertContains('throttle:qr-table-device-read', Route::getRoutes()->getByName('device.qr.pending-orders')->gatherMiddleware());
        $this->assertContains('throttle:qr-table-device-write', Route::getRoutes()->getByName('device.qr.pending-orders.to-counter')->gatherMiddleware());
    }

    public static function refusals(): iterable
    {
        yield 'kind' => [['order_type' => 'dine_in'], 'none', 'order_not_found', 404];
        yield 'source' => [['source' => 'main_pos'], 'none', 'order_not_found', 404];
        yield 'branch' => [['branch_id' => 20], 'none', 'order_not_found', 404];
        yield 'company' => [['company_id' => 200], 'none', 'order_not_found', 404];
        yield 'terminal-before-live' => [['status' => 'paid'], 'live_claim', 'order_not_awaiting_payment', 409];
        yield 'live' => [['status' => 'awaiting_payment'], 'live_claim', 'charge_already_claimed', 409];
        yield 'uncertain-live-scope' => [['status' => 'awaiting_payment'], 'uncertain', 'charge_already_claimed', 409];
        yield 'approved-live-scope' => [['status' => 'awaiting_payment'], 'approved', 'charge_already_claimed', 409];
        yield 'expired-claim' => [['status' => 'awaiting_payment'], 'expired_claim', 'charge_outcome_uncertain', 409];
        yield 'lapsed' => [['status' => 'awaiting_payment'], 'lapsed', 'charge_outcome_uncertain', 409];
        yield 'held-uncertain' => [[], 'uncertain', 'charge_outcome_uncertain', 409];
        yield 'unknown-residue' => [[], 'residue', 'charge_outcome_uncertain', 409];
        yield 'held-no-reference' => [['temp_reference' => null], 'none', 'order_already_held', 409];
        yield 'residue-before-reference' => [['temp_reference' => null], 'residue', 'charge_outcome_uncertain', 409];
    }

    #[DataProvider('refusals')]
    public function test_counter_refusals_write_no_rows(array $attributes, string $kind, string $code, int $status): void
    {
        $order = $this->order($attributes + $this->charge($kind));
        $before = $this->snapshot();
        Log::spy();
        $this->move($order)->assertStatus($status)->assertJsonPath('errors.0.code', $code);
        $this->assertSame($before, $this->snapshot());
        Log::shouldNotHaveReceived('info');
    }

    public static function moves(): iterable
    {
        foreach (['held', 'awaiting_payment'] as $status) {
            foreach (['none', 'declined', 'cancelled'] as $charge) {
                yield "$status/$charge" => [$status, $charge];
            }
        }
    }

    #[DataProvider('moves')]
    public function test_counter_steps_six_to_nine_clear_only_safe_fields_and_replay(string $status, string $kind): void
    {
        $order = $this->order(['status' => $status] + $this->charge($kind), 'timestamp_expired');
        $before = $this->raw($order);
        $session = $this->sessionRaw($order);
        $items = $order->items->toArray();
        $this->travel(1)->seconds();
        Log::spy();
        $response = $this->move($order)->assertOk()->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.actions.settle', true);
        $after = $this->raw($order);
        $this->assertSame($this->withoutCharge($before), $this->withoutCharge($after));
        $this->assertSame($session, $this->sessionRaw($order));
        $this->assertSame($items, $order->fresh()->items->toArray());
        foreach (PresentQrPendingOrderAction::CHARGE_FIELDS as $field) {
            $this->assertNull($after[$field]);
        }
        if ($kind === 'none') {
            $this->assertArrayNotHasKey('cleared_charge', $response->json('data'));
            Log::shouldNotHaveReceived('info');
            if ($status === 'held') {
                $this->assertSame($before, $after);
            }
        } else {
            $previous = array_intersect_key($before, array_flip(PresentQrPendingOrderAction::CHARGE_FIELDS));
            $this->assertEquals($previous, $response->json('data.cleared_charge'));
            Log::shouldHaveReceived('info')->once()->with('qr-pending to-counter', [
                'order_uuid' => $order->uuid, 'device_id' => $this->till->id,
                'previous' => $response->json('data.cleared_charge'),
            ]);
            if (getenv('QR_PENDING_EVIDENCE') === '1' && $status === 'awaiting_payment' && $kind === 'declined') {
                fwrite(STDOUT, "\nA2_CLEARING ".json_encode($response->json(), JSON_UNESCAPED_SLASHES)."\n");
            }
        }
        $replay = $this->move($order)->assertOk();
        $this->assertSame($after, $this->raw($order));
        $this->assertArrayNotHasKey('cleared_charge', $replay->json('data'));
    }

    public function test_missing_phone_is_null_and_payload_sample(): void
    {
        $this->order(['customer_id' => null]);
        $response = $this->getPending()->assertOk()->assertJsonPath('data.orders.0.phone_tail', null);
        if (getenv('QR_PENDING_EVIDENCE') === '1') {
            fwrite(STDOUT, "\nA1_PAYLOAD ".json_encode($response->json(), JSON_UNESCAPED_SLASHES)."\n");
        }
    }
}
