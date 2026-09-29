<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class StaffRoundProposedUuidTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public static function proposals(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    #[DataProvider('proposals')]
    public function test_round_proposal_collision_and_replay_on_both_transports(bool $collision, bool $online): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $proposed = (string) Str::uuid();
        $other = null;
        if ($collision) {
            $other = $this->seatingOrder($this->seatingRow($this->seatingTable('Other')), ['uuid' => $proposed]);
        }
        $otherBefore = $other?->fresh()->getRawOriginal();
        $payload = [
            'seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id,
            'queued_offline' => false, 'client_request_id' => 'proposed-round', 'order_uuid' => $proposed,
            'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => (int) $product->id, 'qty' => 2, 'addon_ids' => [], 'notes' => null]],
        ];
        $send = function (array $body, string $outcome) use ($device, $seating, $online): array {
            $this->withToken($device->plainTextToken);
            if ($online) {
                return $this->postJson('/api/v1/device/tables/'.$seating->uuid.'/round', $body)
                    ->assertOk()->assertJsonPath('data.outcome', $outcome)->json('data');
            }

            return $this->postJson('/api/v1/device/sync/push', ['events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
                'client_timestamp' => now()->toIso8601String(), 'payload' => $body,
            ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed')
                ->assertJsonPath('data.results.0.result.outcome', $outcome)->json('data.results.0.result');
        };
        $first = $send($payload, 'appended');
        $this->assertTrue(Str::isUuid($first['order_uuid']));
        if ($collision) {
            $this->assertNotSame($proposed, $first['order_uuid']);
        } else {
            $this->assertSame($proposed, $first['order_uuid']);
        }
        $bill = Order::query()->where('uuid', $first['order_uuid'])->sole();
        $this->assertSame('2.000', $bill->grand_total);
        $this->assertSame((int) $bill->id, (int) $seating->fresh()->order_id);
        $this->assertSame($otherBefore, $other?->fresh()->getRawOriginal());
        $snapshot = [$bill->getRawOriginal(), QrOrderRound::query()->sole()->getRawOriginal(), OrderItem::query()->get()->toArray()];
        $replay = $send(array_replace($payload, ['order_uuid' => (string) Str::uuid()]), 'replayed');
        $this->assertSame($first['order_uuid'], $replay['order_uuid']);
        $this->assertSame($snapshot, [$bill->fresh()->getRawOriginal(), QrOrderRound::query()->sole()->getRawOriginal(), OrderItem::query()->get()->toArray()]);
        $this->assertDatabaseCount('pos_orders', $collision ? 2 : 1);
        fwrite(STDOUT, "\nT6_UUID_".($collision ? 'COLLISION' : 'PROPOSED').($online ? '_ONLINE' : '_SYNC').'='.json_encode($first, JSON_THROW_ON_ERROR)."\n");
    }
}
