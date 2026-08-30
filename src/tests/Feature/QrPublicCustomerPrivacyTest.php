<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\CreateQrOrderAction;
use App\Actions\Qr\QrCheckoutException;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class QrPublicCustomerPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Device $station;

    private int $sessionSequence = 0;

    private int $requestSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->station = Device::factory()->paired('privacy-station-token')->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => 'payment_station',
        ]);

        DB::table('pos_products')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => 'Privacy Test Coffee',
            'base_price' => '1.000',
            'stock_mode' => 'untracked',
            'display_order' => 1,
            'status' => 'active',
            'show_on_customer_tablet' => true,
            'is_internal' => false,
            'available_from' => null,
            'available_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    public function test_http_checkout_reuses_customers_and_keeps_plate_links_and_orders_normalised(): void
    {
        $first = $this->checkout(
            $this->activeSession('plate-secret-1'),
            'plate-secret-1',
            $this->payload('90000001', '  12345   a  '),
        )->assertCreated();
        $firstOrder = Order::query()->where('uuid', $first->json('data.order.uuid'))->sole();

        $second = $this->checkout(
            $this->activeSession('plate-secret-2'),
            'plate-secret-2',
            $this->payload('90000001', '12345 A'),
        )->assertCreated();
        $secondOrder = Order::query()->where('uuid', $second->json('data.order.uuid'))->sole();

        $shared = $this->checkout(
            $this->activeSession('plate-secret-3'),
            'plate-secret-3',
            $this->payload('90000002', '12345 A'),
        )->assertCreated();
        $sharedOrder = Order::query()->where('uuid', $shared->json('data.order.uuid'))->sole();

        $punctuated = $this->checkout(
            $this->activeSession('plate-secret-4'),
            'plate-secret-4',
            $this->payload('90000001', '12345-A'),
        )->assertCreated();
        $punctuatedOrder = Order::query()->where('uuid', $punctuated->json('data.order.uuid'))->sole();

        $this->assertSame($firstOrder->customer_id, $secondOrder->customer_id);
        $this->assertSame($firstOrder->customer_id, $punctuatedOrder->customer_id);
        $this->assertNotSame($firstOrder->customer_id, $sharedOrder->customer_id);
        $this->assertSame('12345 A', $firstOrder->plate_number);
        $this->assertSame('12345 A', $secondOrder->plate_number);
        $this->assertSame('12345 A', $sharedOrder->plate_number);
        $this->assertSame('12345-A', $punctuatedOrder->plate_number);
        $this->assertDatabaseCount('pos_customers', 2);
        $this->assertSame(2, DB::table('pos_customer_vehicle_plates')
            ->where('customer_id', $firstOrder->customer_id)->count());
        $this->assertSame(2, DB::table('pos_customer_vehicle_plates')
            ->where('plate_number', '12345 A')->count());
        $this->assertDatabaseHas('pos_customer_vehicle_plates', [
            'customer_id' => $firstOrder->customer_id,
            'plate_number' => '12345-A',
        ]);
    }

    public function test_http_checkout_revives_a_deleted_customer_without_accepting_a_public_rename(): void
    {
        $customer = Customer::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Staff Authored Name',
            'phone' => '91112222',
        ]);
        DB::table('pos_customer_vehicle_plates')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'customer_id' => $customer->id,
            'plate_number' => 'OLD 7',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pos_loyalty_rules')->insert([
            'id' => 51,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Points',
            'type' => 'spend_based',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pos_loyalty_accounts')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'customer_id' => $customer->id,
            'loyalty_rule_id' => 51,
            'point_balance' => 44,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer->delete();

        $payload = $this->payload('91112222');
        $payload['name'] = 'Public Attacker Rename';
        $response = $this->checkout(
            $this->activeSession('revive-secret'),
            'revive-secret',
            $payload,
        )->assertCreated();

        $order = Order::query()->where('uuid', $response->json('data.order.uuid'))->sole();
        $this->assertSame((int) $customer->id, (int) $order->customer_id);
        $this->assertDatabaseHas('pos_customers', [
            'id' => $customer->id,
            'name' => 'Staff Authored Name',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('pos_customer_vehicle_plates', [
            'customer_id' => $customer->id,
            'plate_number' => 'OLD 7',
        ]);
        $this->assertDatabaseHas('pos_loyalty_accounts', [
            'customer_id' => $customer->id,
            'point_balance' => 44,
        ]);
    }

    public function test_known_and_unknown_customer_checkout_bodies_are_identical_after_identifier_normalisation(): void
    {
        Customer::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Known Customer',
            'phone' => '92220001',
        ]);

        $known = $this->checkout(
            $this->activeSession('known-secret'),
            'known-secret',
            $this->payload('92220001'),
        )->assertCreated();
        $unknown = $this->checkout(
            $this->activeSession('unknown-secret'),
            'unknown-secret',
            $this->payload('92220002'),
        )->assertCreated();

        $this->assertSame(
            $this->normaliseOrderIdentifiers($known),
            $this->normaliseOrderIdentifiers($unknown),
        );
    }

    public function test_customer_existence_does_not_change_the_validation_failure_body(): void
    {
        Customer::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Known Invalid Customer',
            'phone' => '93330001',
        ]);

        $knownPayload = $this->payload('93330001');
        $knownPayload['lines'] = [];
        $unknownPayload = $this->payload('93330002');
        $unknownPayload['lines'] = [];

        $known = $this->checkout(
            $this->activeSession('known-invalid-secret'),
            'known-invalid-secret',
            $knownPayload,
        )->assertUnprocessable();
        $unknown = $this->checkout(
            $this->activeSession('unknown-invalid-secret'),
            'unknown-invalid-secret',
            $unknownPayload,
        )->assertUnprocessable();

        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_public_identity_responses_expose_no_customer_pii(): void
    {
        $customerUuid = '018f9e76-6f0d-7f90-8000-000000000001';
        $customerName = 'SENSITIVE CUSTOMER NAME';
        $phone = '94440001';
        $plate = 'PRIVATE 77';
        $customer = Customer::query()->create([
            'uuid' => $customerUuid,
            'company_id' => 100,
            'name' => $customerName,
            'phone' => $phone,
        ]);
        DB::table('pos_customer_vehicle_plates')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'customer_id' => $customer->id,
            'plate_number' => $plate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pending = $this->pendingSession();
        $bind = $this->postJson('/api/v1/public/qr/bind', [
            'token' => $pending->token,
            'client_secret' => 'bind-privacy-secret',
        ])->assertOk();

        $secret = 'pii-checkout-secret';
        $session = $this->activeSession($secret);
        $headers = $this->credentialHeaders($session, $secret);
        $menu = $this->withHeaders($headers)->getJson('/api/v1/public/qr/menu')->assertOk();
        $quote = $this->withHeaders($headers)->postJson('/api/v1/public/qr/quote', [
            'lines' => $this->payload($phone)['lines'],
        ])->assertOk();
        $checkout = $this->checkout(
            $session,
            $secret,
            $this->payload($phone, $plate),
        )->assertCreated();
        $status = $this->withHeaders($headers)->getJson('/api/v1/public/qr/status')->assertOk();

        $responses = [$bind, $menu, $quote, $checkout, $status];
        foreach ($responses as $response) {
            $content = $response->getContent();
            $this->assertStringNotContainsString($customerUuid, $content);
            $this->assertStringNotContainsString($customerName, $content);
            $this->assertStringNotContainsString($phone, $content);
            $this->assertStringNotContainsString($plate, $content);

            $keys = $this->nestedKeys($response->json());
            foreach ([
                'customer_id', 'customer_uuid', 'phone', 'plate_number', 'plates',
                'wallet_balance', 'wallet_balance_baisas', 'loyalty', 'points', 'stamps',
                'is_new', 'is_new_customer', 'is_returning',
            ] as $forbiddenKey) {
                $this->assertNotContains($forbiddenKey, $keys);
            }
        }

        // Catalogue product/category/add-on names are deliberately public.
        // Identity-bearing responses must not carry any generic customer name.
        foreach ([$bind, $checkout, $status] as $identityResponse) {
            $this->assertNotContains('name', $this->nestedKeys($identityResponse->json()));
        }
    }

    public function test_public_qr_route_enumeration_contains_no_loyalty_redeem_surface(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/v1/public/qr'));

        $this->assertSame([
            'public.qr.bind',
            'public.qr.checkout',
            'public.qr.menu',
            'public.qr.quote',
            'public.qr.status',
            'public.qr.table-bind',
            'public.qr.table-finish',
            'public.qr.table-menu',
            'public.qr.table-round',
        ], $routes->pluck('action.as')->sort()->values()->all());

        foreach ($routes as $route) {
            $surface = strtolower(implode(' ', [
                $route->uri(),
                (string) $route->getName(),
                $route->getActionName(),
            ]));
            $this->assertStringNotContainsString('redeem', $surface);
            $this->assertStringNotContainsString('applyloyaltyredeemaction', $surface);
        }
    }

    public function test_every_public_qr_route_has_exactly_one_named_qr_throttle(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/v1/public/qr'));

        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $middleware = array_values($route->gatherMiddleware());
            $throttles = array_values(array_filter(
                $middleware,
                static fn (string $name): bool => str_starts_with($name, 'throttle:'),
            ));

            $this->assertCount(1, $throttles, 'Missing or duplicate throttle on '.$route->uri());
            $this->assertMatchesRegularExpression(
                '/^throttle:qr-[a-z0-9-]+$/',
                $throttles[0],
                'Public QR routes must use a named QR limiter: '.$route->uri(),
            );

            $sessionIndex = collect($middleware)->search(
                static fn (string $name): bool => str_starts_with($name, 'qr.session'),
            );
            if ($sessionIndex !== false) {
                $this->assertLessThan(
                    $sessionIndex,
                    array_search($throttles[0], $middleware, true),
                    'Throttle must run before session resolution: '.$route->uri(),
                );
            }
        }
    }

    public function test_six_customers_on_six_sessions_from_one_ip_and_branch_all_checkout(): void
    {
        $clientIp = '198.51.100.60';

        for ($customer = 1; $customer <= 6; $customer++) {
            $secret = 'shared-nat-secret-'.$customer;
            $response = $this->checkout(
                $this->activeSession($secret),
                $secret,
                $this->payload('9555010'.$customer),
                $clientIp,
            )->assertCreated();
            $this->assertSame($clientIp, $response->baseRequest?->ip());
        }

        $this->assertDatabaseCount('pos_orders', 6);
        $this->assertDatabaseCount('pos_customers', 6);
    }

    public function test_eleven_nginx_attributed_customers_across_two_branches_all_checkout(): void
    {
        $secondStation = Device::factory()->paired('privacy-second-station-token')->create([
            'company_id' => 100,
            'branch_id' => 20,
            'device_type' => 'payment_station',
        ]);
        for ($customer = 1; $customer <= 11; $customer++) {
            $station = $customer <= 6 ? $this->station : $secondStation;
            $secret = 'eleven-customer-secret-'.$customer;
            $clientIp = '203.0.113.'.$customer;
            $response = $this->checkout(
                $this->activeSession($secret, station: $station),
                $secret,
                $this->payload('95552'.str_pad((string) $customer, 3, '0', STR_PAD_LEFT)),
                $clientIp,
            )->assertCreated();
            $this->assertSame($clientIp, $response->baseRequest?->ip());
        }

        $this->assertDatabaseCount('pos_orders', 11);
        $this->assertDatabaseCount('pos_customers', 11);
        $this->assertSame(6, Order::query()->where('branch_id', 10)->count());
        $this->assertSame(5, Order::query()->where('branch_id', 20)->count());
    }

    public function test_fourth_distinct_phone_from_one_session_is_refused_by_the_http_endpoint(): void
    {
        // Abort each of the first three writes after the external identity guard
        // records the phone. Their DB transactions roll back, so the fourth HTTP
        // request reaches the same active session and its distinct-phone ceiling.
        Order::creating(static function (Order $order): void {
            throw new QrCheckoutException('test_order_abort', 409, 'Test order write aborted.');
        });

        $secret = 'same-session-guard-secret';
        $session = $this->activeSession($secret);
        foreach (['95550001', '95550002', '95550003'] as $phone) {
            $this->checkout($session, $secret, $this->payload($phone))
                ->assertStatus(409)
                ->assertJsonPath('errors.0.code', 'test_order_abort');
        }

        $this->checkout($session, $secret, $this->payload('95550004'))
            ->assertStatus(429)
            ->assertJsonPath('errors.0.code', 'qr_identity_limit_exceeded');
        $this->assertSame(QrSession::STATUS_ACTIVE, $session->fresh()->status);
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_action_persists_expiry_that_occurs_after_middleware_resolution(): void
    {
        $resolvedAt = Carbon::parse('2026-08-26 12:00:00');
        $this->travelTo($resolvedAt);
        $secret = 'boundary-expiry-secret';
        $deadline = $resolvedAt->copy()->addSecond();
        $session = $this->activeSession($secret, ['expires_at' => $deadline]);

        // This is the successful middleware resolution immediately before the
        // clock crosses the session boundary.
        $this->withHeaders($this->credentialHeaders($session, $secret))
            ->getJson('/api/v1/public/qr/menu')
            ->assertOk();

        $this->travelTo($deadline);
        try {
            app(CreateQrOrderAction::class)->handle(
                (int) $session->id,
                $this->payload('97770001'),
                '198.51.100.77',
            );
            $this->fail('Expected the checkout action to reject the newly expired session.');
        } catch (QrCheckoutException $exception) {
            $this->assertSame('qr_session_not_found', $exception->codeName);
            $this->assertSame(404, $exception->httpStatus);
        }

        $session->refresh();
        $this->assertSame(QrSession::STATUS_EXPIRED, $session->status);
        $this->assertTrue($session->closed_at->equalTo($deadline));
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_qr_session_schema_has_exactly_the_non_pii_contract_columns(): void
    {
        $actual = Schema::getColumnListing('pos_qr_sessions');
        $expected = [
            'id',
            'uuid',
            'company_id',
            'branch_id',
            'device_id',
            'table_id',
            'token',
            'token_expires_at',
            'status',
            'client_secret_hash',
            'bound_at',
            'secret_rotated_at',
            'last_seen_at',
            'expires_at',
            'closed_at',
            'created_at',
            'updated_at',
        ];
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual);
        foreach (['phone', 'plate', 'name', 'customer_id', 'ip', 'user_agent'] as $piiFragment) {
            $this->assertFalse(collect($actual)->contains(
                static fn (string $column): bool => str_contains($column, $piiFragment),
            ));
        }
    }

    public function test_named_qr_limiter_keys_ignore_raw_phone_and_plate_values(): void
    {
        $phone = '96660001';
        $plate = 'LIMITER 88';
        $token = str_repeat('t', 64);
        $session = 'raw-session-credential';
        $request = Request::create('/api/v1/public/qr/checkout', 'POST', [
            'token' => $token,
            'phone' => $phone,
            'plate_number' => $plate,
        ], server: ['REMOTE_ADDR' => '192.0.2.44']);
        $request->headers->set('X-QR-Session', $session);

        $rateLimiter = app(RateLimiter::class);
        $expected = [
            'qr-bind' => [
                ['qr-bind:ip:192.0.2.44', 10, 60],
                ['qr-bind:token:'.hash('sha256', $token), 10, 60],
            ],
            'qr-read' => [
                ['qr-read:session:'.hash('sha256', $session), 60, 60],
                ['qr-read:ip:192.0.2.44', 3000, 60],
            ],
            'qr-quote' => [
                ['qr-quote:session:'.hash('sha256', $session), 30, 60],
                ['qr-quote:ip:192.0.2.44', 600, 60],
            ],
            'qr-checkout' => [
                ['qr-checkout:ip:192.0.2.44', 10, 60],
                ['qr-checkout:session:'.hash('sha256', $session), 10, 60],
            ],
        ];

        foreach ($expected as $name => $expectedLimits) {
            $definition = $rateLimiter->limiter($name);
            $this->assertNotNull($definition);
            $resolved = $definition($request);
            $limits = is_array($resolved) ? $resolved : [$resolved];
            $actualLimits = array_map(
                static fn ($limit): array => [
                    (string) $limit->key,
                    $limit->maxAttempts,
                    $limit->decaySeconds,
                ],
                $limits,
            );
            $this->assertSame($expectedLimits, $actualLimits);

            $keys = implode('|', array_column($actualLimits, 0));
            $this->assertStringNotContainsString($phone, $keys);
            $this->assertStringNotContainsString($plate, $keys);
            $this->assertStringNotContainsString($token, $keys);
            $this->assertStringNotContainsString($session, $keys);
        }

        $allKeys = json_encode($expected, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(hash('sha256', $token), $allKeys);
        $this->assertStringContainsString(hash('sha256', $session), $allKeys);
    }

    /** @param  array<string, mixed>  $payload */
    private function checkout(
        QrSession $session,
        string $secret,
        array $payload,
        string $ip = '198.51.100.20',
    ): TestResponse {
        $headers = $this->credentialHeaders($session, $secret);

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders($headers)
            ->postJson('/api/v1/public/qr/checkout', $payload);
    }

    /** @return array<string, string> */
    private function credentialHeaders(QrSession $session, string $secret): array
    {
        return [
            'X-QR-Session' => (string) $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ];
    }

    /** @return array<string, mixed> */
    private function payload(string $phone, ?string $plate = null): array
    {
        $this->requestSequence++;

        return [
            'client_request_id' => 'privacy-request-'.$this->requestSequence,
            'checkout_choice' => 'machine',
            'phone' => $phone,
            'plate_number' => $plate,
            'lines' => [[
                'product_id' => 1,
                'qty' => 1,
                'addon_ids' => [],
                'notes' => null,
            ]],
        ];
    }

    private function activeSession(
        string $secret,
        array $attributes = [],
        ?Device $station = null,
    ): QrSession {
        return $this->qrSession($attributes + [
            'status' => QrSession::STATUS_ACTIVE,
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'bound_at' => now(),
            'last_seen_at' => now(),
        ], $station);
    }

    private function pendingSession(): QrSession
    {
        return $this->qrSession([
            'status' => QrSession::STATUS_PENDING,
            'client_secret_hash' => null,
            'bound_at' => null,
            'last_seen_at' => null,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function qrSession(array $attributes, ?Device $station = null): QrSession
    {
        $this->sessionSequence++;
        $now = now();
        $station ??= $this->station;

        return QrSession::query()->create($attributes + [
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->id,
            'token' => hash('sha256', 'privacy-token-'.$this->sessionSequence),
            'token_expires_at' => $now->copy()->addMinute(),
            'expires_at' => $now->copy()->addMinutes(30),
        ]);
    }

    private function normaliseOrderIdentifiers(TestResponse $response): string
    {
        $content = $response->getContent();
        foreach (['data.order.uuid', 'data.order.receipt_number'] as $path) {
            $identifier = $response->json($path);
            if (is_string($identifier)) {
                $content = str_replace($identifier, '<order-identifier>', $content);
            }
        }

        return $content;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function nestedKeys(array $payload): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                array_push($keys, ...$this->nestedKeys($value));
            }
        }

        return $keys;
    }
}
