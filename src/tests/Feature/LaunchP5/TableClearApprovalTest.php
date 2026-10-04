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
use Illuminate\Testing\TestResponse;
use Tests\Support\LaunchP5Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 2 (A1, device review M1) — a "clear" of several sent
 * lines with ONE manager approval. Each request carries its own proof (ref =
 * its client_request_id; the till sends a fresh seating_key per request, the
 * proof's subject), and the replay guard lets the same approval (approver +
 * action + approved_at) verify for several requests of the same table
 * session — the one the server resolved — while it is at most 10 minutes
 * old. On another table session, or after the window, it is still refused
 * (proof_reused).
 */
class TableClearApprovalTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
        $this->device = $this->seatingDevice();
    }

    /** A live table with a sent round of 3 × product. @return array{0: TableSession, 1: Product, 2: array<string, mixed>} */
    private function table(string $label): array
    {
        $seat = $this->seatingRow($this->seatingTable($label), ['opened_by_device_id' => $this->device->id]);
        $product = $this->seatingProduct();
        $prefix = ['table_id' => (int) $seat->table_id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false, 'staff_id' => 7];
        $this->p5Online($this->device, 'POST', '/api/v1/device/tables/'.$seat->uuid.'/round', $prefix + ['auth_v' => 1,
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $product->id, 'qty' => 3]]])->assertOk()->assertJsonPath('data.outcome', 'appended');

        return [$seat->refresh(), $product, $prefix];
    }

    /**
     * One line's cancel as the till sends it: a fresh seating_key and its own
     * proof over the shared approval.
     *
     * @param  array<string, mixed>  $prefix
     * @return array<string, mixed>
     */
    private function cancel(array $prefix, Product $product, string $approvedAt, bool $freshKey = true): array
    {
        $id = (string) Str::uuid();
        $key = $freshKey ? (string) Str::uuid() : (string) $prefix['seating_key'];

        return array_replace($prefix, ['seating_key' => $key]) + ['auth_v' => 1, 'client_request_id' => $id,
            'product_id' => (int) $product->id, 'qty' => 1, 'prepared' => false, 'reason' => 'Clear',
            'cancelled_at' => now()->toIso8601String(),
            'authorization' => $this->p5Approval($this->device, 'table.cancel_line', 8, 7, $key, null, $id, $approvedAt)];
    }

    private function send(TableSession $seat, array $body): TestResponse
    {
        return $this->p5Online($this->device, 'POST', '/api/v1/device/tables/'.$seat->uuid.'/cancel-line', $body);
    }

    private static function at(Carbon $at): string
    {
        return $at->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public function test_one_approval_clears_several_lines_of_the_same_table_session(): void
    {
        [$seat, $product, $prefix] = $this->table('T1');
        $approvedAt = self::at(now()->subMinute());

        foreach ([1, 2, 3] as $line) {
            $this->send($seat, $this->cancel($prefix, $product, $approvedAt))->assertOk()->assertJsonPath('data.cancelled_qty', 1);
        }

        $rows = DB::table('pos_approvals')->orderBy('id')->get(['result', 'subject_uuid', 'approver_staff_id']);
        $this->assertSame([['verified', $seat->client_request_id, 8], ['verified', $seat->client_request_id, 8],
            ['verified', $seat->client_request_id, 8]],
            $rows->map(fn ($r): array => [$r->result, $r->subject_uuid, (int) $r->approver_staff_id])->all());
    }

    public function test_the_same_approval_on_another_table_session_is_refused(): void
    {
        [$first, $product, $prefix] = $this->table('T1');
        [$second, $product2, $prefix2] = $this->table('T2');
        $approvedAt = self::at(now()->subMinute());

        $this->send($first, $this->cancel($prefix, $product, $approvedAt))->assertOk();
        $this->send($second, $this->cancel($prefix2, $product2, $approvedAt))
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'approval_invalid')->assertJsonPath('data.reason', 'proof_reused');
    }

    public function test_after_the_window_the_same_approval_is_not_shared_any_more(): void
    {
        [$seat, $product, $prefix] = $this->table('T1');
        // A queued event finds its seating by its key (no route uuid).
        $queued = function (string $approvedAt) use ($prefix, $product): array {
            $body = $this->cancel(array_replace($prefix, ['queued_offline' => true]), $product, $approvedAt, freshKey: false);

            return $this->p5Event('table.session.cancel_line', $body);
        };

        // Queued offline events keep their offline approval time: inside the
        // window two lines share it, outside it only the first counts.
        $recent = self::at(now()->subMinutes(2));
        $this->p5Push($this->device->plainTextToken, [$queued($recent), $queued($recent)])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')->assertJsonPath('data.results.0.result.cancelled_qty', 1)
            ->assertJsonPath('data.results.1.status', 'processed')->assertJsonPath('data.results.1.result.cancelled_qty', 1);

        $old = self::at(now()->subHours(2));
        $this->p5Push($this->device->plainTextToken, [$queued($old)])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')->assertJsonPath('data.results.0.result.cancelled_qty', 1);
        $this->p5Push($this->device->plainTextToken, [$queued($old)])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed')->assertJsonPath('data.results.0.result.refusal_code', 'approval_invalid');

        $this->assertSame(['verified', 'verified', 'verified'], DB::table('pos_approvals')->orderBy('id')->pluck('result')->all());
    }
}
