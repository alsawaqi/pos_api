<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Models\Device;
use App\Models\Product;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (F3, review M1) — no replay on online actions. For
 * the table operations and sold-out the block's ref must equal the
 * request's client_request_id (failed `ref_mismatch`), and online an
 * approval's approved_at must be within 10 minutes of the server time
 * (failed `approval_stale`); both refuse with 403 approval_invalid. A queued
 * table sync event keeps its offline approval time but not another
 * request's block. Dedupe stays on (device, client_event_id, action, ref).
 */
class OnlineReplayTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
    }

    /** @return array{0: Device, 1: TableSession, 2: Product, 3: array<string, mixed>} */
    private function table(int $qty = 4): array
    {
        $device = $this->seatingDevice();
        $seat = $this->seatingRow($this->seatingTable('P5 table'), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $prefix = ['table_id' => (int) $seat->table_id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false, 'staff_id' => 7];
        $this->p5Online($device, 'POST', '/api/v1/device/tables/'.$seat->uuid.'/round', $prefix + ['auth_v' => 1,
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $product->id, 'qty' => $qty]],
        ])->assertOk()->assertJsonPath('data.outcome', 'appended');

        return [$device, $seat->refresh(), $product, $prefix];
    }

    private static function at(\DateTimeInterface $at): string
    {
        return Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public function test_one_table_approval_authorizes_its_own_request_only(): void
    {
        // Review probe 2: the same block re-sent on further cancel-line requests.
        [$device, $seat, $product, $prefix] = $this->table();
        $cancel = fn (string $id, array $block): array => $prefix + ['auth_v' => 1, 'client_request_id' => $id,
            'product_id' => (int) $product->id, 'qty' => 1, 'prepared' => false, 'reason' => 'Wrong item',
            'cancelled_at' => now()->toIso8601String(), 'authorization' => $block];
        $url = '/api/v1/device/tables/'.$seat->uuid.'/cancel-line';
        $first = (string) Str::uuid();
        $block = $this->p5Approval($device, 'table.cancel_line', 8, 7, $seat->client_request_id, null, $first);

        $this->p5Online($device, 'POST', $url, $cancel($first, $block))->assertOk()->assertJsonPath('data.cancelled_qty', 1);
        foreach ([(string) Str::uuid(), (string) Str::uuid()] as $replay) {
            $this->p5Online($device, 'POST', $url, $cancel($replay, $block))
                ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_invalid')->assertJsonPath('data.reason', 'ref_mismatch');
        }
        // A block with no ref (or the seating's key) is no better.
        $this->p5Online($device, 'POST', $url, $cancel((string) Str::uuid(),
            $this->p5Approval($device, 'table.cancel_line', 8, 7, $seat->client_request_id)))
            ->assertStatus(403)->assertJsonPath('data.reason', 'ref_mismatch');
        $this->assertSame(1, DB::table('pos_approvals')->count());
        $this->assertSame($first, DB::table('pos_approvals')->value('client_event_id'));
        $this->assertSame($first, DB::table('pos_approvals')->value('ref'));
    }

    public function test_an_online_table_approval_must_be_fresh(): void
    {
        [$device, $seat, $product, $prefix] = $this->table();
        $url = '/api/v1/device/tables/'.$seat->uuid.'/cancel-line';
        $send = function (\DateTimeInterface $approvedAt) use ($device, $seat, $product, $prefix, $url) {
            $id = (string) Str::uuid();

            return $this->p5Online($device, 'POST', $url, $prefix + ['auth_v' => 1, 'client_request_id' => $id,
                'product_id' => (int) $product->id, 'qty' => 1, 'prepared' => false, 'reason' => 'x',
                'cancelled_at' => now()->toIso8601String(),
                'authorization' => $this->p5Approval($device, 'table.cancel_line', 8, 7, $seat->client_request_id, null, $id, self::at($approvedAt))]);
        };

        $send(now()->subDays(3))->assertStatus(403)->assertJsonPath('data.reason', 'approval_stale');
        $send(now()->subMinutes(11))->assertStatus(403)->assertJsonPath('data.reason', 'approval_stale');
        $send(now()->addMinutes(11))->assertStatus(403)->assertJsonPath('data.reason', 'approval_stale');
        $send(now()->subMinutes(9))->assertOk()->assertJsonPath('data.cancelled_qty', 1);
        $this->assertSame(['verified'], DB::table('pos_approvals')->pluck('result')->all());
    }

    public function test_a_queued_table_event_keeps_its_offline_approval_time_but_not_another_requests_block(): void
    {
        [$device, $seat, $product, $prefix] = $this->table();
        $event = function (string $id, array $block) use ($prefix, $product): array {
            return $this->p5Event('table.session.cancel_line', array_replace($prefix, ['queued_offline' => true]) + ['auth_v' => 1,
                'client_request_id' => $id, 'product_id' => (int) $product->id, 'qty' => 1, 'prepared' => false, 'reason' => 'x',
                'cancelled_at' => now()->subHours(2)->toIso8601String(), 'authorization' => $block]);
        };
        $id = (string) Str::uuid();
        $block = $this->p5Approval($device, 'table.cancel_line', 8, 7, $seat->client_request_id, null, $id, self::at(now()->subHours(2)));

        $this->p5Push($device->plainTextToken, [$event($id, $block)])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')->assertJsonPath('data.results.0.result.cancelled_qty', 1);
        $this->p5Push($device->plainTextToken, [$event((string) Str::uuid(), $block)])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed')->assertJsonPath('data.results.0.result.refusal_code', 'approval_invalid');
        $this->assertSame(['verified'], DB::table('pos_approvals')->pluck('result')->all());
    }

    public function test_sold_out_binds_the_block_to_its_request_and_needs_a_fresh_approval(): void
    {
        $device = $this->p5Device('mdev_so');
        $uuid = (string) Str::uuid();
        DB::table('pos_products')->insert(['id' => 5, 'uuid' => $uuid, 'company_id' => 100, 'name' => 'Cake', 'base_price' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $url = '/api/v1/device/products/5/sold-out';
        $toggle = fn (bool $soldOut, ?string $id, array $block): array => ['sold_out' => $soldOut, 'staff_id' => 7, 'auth_v' => 1,
            'authorization' => $block] + ($id === null ? [] : ['client_request_id' => $id]);

        $first = (string) Str::uuid();
        $block = $this->p5Approval($device, 'sold_out.toggle', 8, 7, $uuid, null, $first);
        $this->p5Online($device, 'POST', $url, $toggle(true, $first, $block))->assertOk()->assertJsonPath('data.changed', true);
        // Replayed on a new request, or with no request id: refused.
        $this->p5Online($device, 'POST', $url, $toggle(false, (string) Str::uuid(), $block))
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_invalid');
        $this->p5Online($device, 'POST', $url, $toggle(false, null,
            $this->p5Approval($device, 'sold_out.toggle', 8, 7, $uuid, null, null, self::at(now()->subMinutes(4)))))
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_invalid');
        // An approval three days old: refused.
        $old = (string) Str::uuid();
        $this->p5Online($device, 'POST', $url, $toggle(false, $old,
            $this->p5Approval($device, 'sold_out.toggle', 8, 7, $uuid, null, $old, self::at(now()->subDays(3)))))
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_invalid');

        $this->assertSame([['verified', null], ['failed', 'ref_mismatch'], ['failed', 'ref_mismatch'], ['failed', 'approval_stale']],
            DB::table('pos_approvals')->orderBy('id')->get(['result', 'reason'])->map(fn ($r): array => [$r->result, $r->reason])->all());
        $this->assertSame(1, DB::table('pos_product_sold_out')->count());
    }
}
