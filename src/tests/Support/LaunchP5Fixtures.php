<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * LAUNCH-P5 test fixtures: staff with (or without) an offline verifier, and
 * approval proofs built straight from the data-contract formula with PHP's
 * own hash functions (independent of the server's ApproverVerifier, which the
 * golden-vector test pins separately).
 */
trait LaunchP5Fixtures
{
    /** Low PBKDF2 cost for fixtures (the real cost is config + per row). */
    protected int $p5Iterations = 1000;

    protected function p5Device(string $token = 'mdev_p5', int $company = 100, int $branch = 10, array $extra = []): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch] + $extra);
    }

    /**
     * A staff row; with $verifier the offline K/salt/iterations are stored
     * (as pos_merchant or a login would have made them).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function p5Staff(int $id, string $position, string $pin, bool $verifier = false, array $overrides = []): int
    {
        $row = array_merge([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'name' => ucfirst($position).' '.$id,
            'pin_hash' => Hash::make($pin),
            'position' => $position,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
        if ($verifier) {
            $salt = bin2hex(random_bytes(16));
            $row += [
                'pin_offline_salt' => $salt,
                'pin_offline_iterations' => $this->p5Iterations,
                'pin_offline_key' => bin2hex(hash_pbkdf2('sha256', $pin, (string) hex2bin($salt), $this->p5Iterations, 32, true)),
            ];
        }
        DB::table('pos_staff')->insert($row);

        return $id;
    }

    /** K (raw) of a staff row's stored verifier. */
    protected function p5Key(int $staffId): string
    {
        return (string) hex2bin((string) DB::table('pos_staff')->where('id', $staffId)->value('pin_offline_key'));
    }

    /** The contract's proof: hex(HMAC-SHA256(K, canonical)). */
    protected function p5Proof(int $approverId, string $action, string $deviceUuid, string $approvedAt,
        ?string $subject = null, ?int $amount = null, ?string $ref = null): string
    {
        $canonical = 'v1|'.$action.'|'.$deviceUuid.'|'.$approverId.'|'.$approvedAt.'|'.($subject ?? '')
            .'|'.($amount === null ? '' : (string) $amount).'|'.($ref ?? '');

        return hash_hmac('sha256', $canonical, $this->p5Key($approverId));
    }

    /**
     * An approval block with a valid proof.
     *
     * @return array<string, mixed>
     */
    protected function p5Approval(Device $device, string $action, int $approverId, int $actorId, ?string $subject = null,
        ?int $amount = null, ?string $ref = null, ?string $approvedAt = null): array
    {
        $approvedAt ??= now()->subMinutes(5)->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'action' => $action, 'ref' => $ref, 'mode' => 'approval', 'actor_staff_id' => $actorId,
            'approver_staff_id' => $approverId, 'approved_at' => $approvedAt, 'method' => 'offline',
            'proof' => $this->p5Proof($approverId, $action, (string) $device->uuid, $approvedAt, $subject, $amount, $ref),
        ];
    }

    /** @return array<string, mixed> */
    protected function p5Position(string $action, int $actorId, ?string $ref = null): array
    {
        return ['action' => $action, 'ref' => $ref, 'mode' => 'position', 'actor_staff_id' => $actorId];
    }

    /** One untracked product (id 1, 1.000) so order events need no stock. */
    protected function p5Product(): void
    {
        DB::table('pos_products')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Tea',
            'base_price' => 1.000, 'status' => 'active', 'stock_mode' => 'untracked', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function p5Create(string $uuid, array $order = [], array $payload = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->subMinutes(10)->toIso8601String(),
            'payload' => $payload + ['order' => array_merge([
                'uuid' => $uuid, 'order_type' => 'quick', 'source' => 'main_pos', 'staff_id' => 7,
                'opened_at' => now()->subMinutes(10)->toIso8601String(),
                'subtotal_baisas' => 10000, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => 10000,
                'lines' => [['product_id' => 1, 'qty' => 10, 'unit_price_baisas' => 1000, 'line_total_baisas' => 10000]],
            ], $order)],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function p5Pay(string $uuid, array $payments, array $payload = [], ?string $paidAt = null): array
    {
        $paidAt ??= now()->subMinutes(9)->toIso8601String();

        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => $paidAt,
            'payload' => $payload + ['order_uuid' => $uuid, 'paid_at' => $paidAt, 'payments' => $payments],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function p5Event(string $type, array $payload, ?string $clientEventId = null, ?string $at = null): array
    {
        return [
            'client_event_id' => $clientEventId ?? (string) Str::uuid(),
            'event_type' => $type,
            'client_timestamp' => $at ?? now()->subMinute()->toIso8601String(),
            'payload' => $payload,
        ];
    }

    /** The `iat` of every fixture staff token of the current test (taken once). */
    protected ?int $p5TokenIssuedAt = null;

    /**
     * The contract's staff token (fix order 1 F1), built straight from the
     * formula: base64url(json{v,d,s,iat}) "." base64url(HMAC-SHA256(key,
     * first part)), key = HMAC-SHA256("pos-staff-token-v1", app.key).
     *
     * Without $issuedAt every token of one test shares one `iat`, so the same
     * (device, staff) always gets the same token — as a device keeps the token
     * with its outbox row, a re-sent event is byte-for-byte the same even
     * when the re-push crosses a second boundary.
     */
    protected function p5StaffToken(Device|int $device, int $staffId, ?int $issuedAt = null): string
    {
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $body = $b64((string) json_encode(['v' => 1, 'd' => $device instanceof Device ? (int) $device->id : $device,
            's' => $staffId, 'iat' => $issuedAt ?? ($this->p5TokenIssuedAt ??= time())]));
        $key = hash_hmac('sha256', 'pos-staff-token-v1', (string) config('app.key'), true);

        return $body.'.'.$b64(hash_hmac('sha256', $body, $key, true));
    }

    /**
     * Push events as a P5 device does: every P5 event (auth_v 1) that does
     * not set `staff_token` itself gets the token of the person who made it
     * (order.create: order.staff_id; shift.close: closed_by_staff_id; else
     * staff_id). Pass `staff_token => null` to send none.
     *
     * @param  list<array<string, mixed>>  $events
     */
    protected function p5Push(string $token, array $events): TestResponse
    {
        $deviceId = (int) Device::query()->where('device_token', hash('sha256', $token))->value('id');
        foreach ($events as &$event) {
            $payload = (array) ($event['payload'] ?? []);
            $order = (array) ($payload['order'] ?? []);
            if ((($payload['auth_v'] ?? null) !== 1 && ($order['auth_v'] ?? null) !== 1)
                || array_key_exists('staff_token', $payload) || array_key_exists('staff_token', $order)) {
                continue;
            }
            $staff = match ($event['event_type'] ?? null) {
                'order.create' => $order['staff_id'] ?? null,
                'shift.close' => $payload['closed_by_staff_id'] ?? null,
                default => $payload['staff_id'] ?? null,
            };
            if ($staff !== null && $deviceId > 0) {
                $event['payload']['staff_token'] = $this->p5StaffToken($deviceId, (int) $staff);
            }
        }
        unset($event);
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    /**
     * An online call as a P5 device makes it: a P5 body (auth_v 1) naming a
     * staff_id carries that person's X-Staff-Token unless $staffToken says
     * otherwise ('' = no header).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function p5Online(Device $device, string $method, string $path, array $payload = [], ?string $staffToken = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $staffToken ??= ($payload['auth_v'] ?? null) === 1 && isset($payload['staff_id'])
            ? $this->p5StaffToken($device, (int) $payload['staff_id']) : '';
        $headers = $staffToken === '' ? [] : ['X-Staff-Token' => $staffToken];

        return $this->withToken((string) $device->plainTextToken)->json($method, $path, $payload, $headers);
    }

    /** @return list<array<string, mixed>> */
    protected function p5Approvals(): array
    {
        return DB::table('pos_approvals')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
    }
}
