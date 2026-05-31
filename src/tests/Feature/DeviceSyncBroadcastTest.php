<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\DeviceSyncBroadcast;
use App\Models\Device;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §11.5 — real-time push. A processed sync event broadcasts DeviceSyncBroadcast
 * on the device's branch channel so the branch's other terminals learn about it
 * live; a failed or unhandled event broadcasts nothing.
 *
 * We capture via a real Event::listen on the shared dispatcher (rather than
 * Event::fake) — it exercises the true dispatch path and the broadcaster stays
 * `null` in tests (phpunit.xml), so nothing hits a socket.
 *
 * @return array<int, DeviceSyncBroadcast> filled by the registered listener
 */
class DeviceSyncBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<DeviceSyncBroadcast> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured = [];
        Event::listen(DeviceSyncBroadcast::class, function (DeviceSyncBroadcast $e): void {
            $this->captured[] = $e;
        });
    }

    private function device(): Device
    {
        return Device::factory()->paired('mdev_bcast')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @return array<string, mixed>
     */
    private function createEvent(string $uuid, array $orderOverrides = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => array_merge([
                'uuid' => $uuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'opened_at' => now()->toIso8601String(),
                'subtotal_baisas' => 3000,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 3000,
                'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_discount_baisas' => 0, 'line_total_baisas' => 3000]],
            ], $orderOverrides)],
        ];
    }

    private function push(array $event): void
    {
        $this->withToken('mdev_bcast')
            ->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk();
    }

    public function test_a_processed_event_broadcasts_on_the_branch_channel(): void
    {
        $this->device();
        $this->push($this->createEvent((string) Str::uuid()));

        $this->assertCount(1, $this->captured);
        $broadcast = $this->captured[0];

        $this->assertSame('order.create', $broadcast->type);
        $this->assertSame(100, $broadcast->companyId);
        $this->assertSame(10, $broadcast->branchId);
        $this->assertSame('order.create', $broadcast->broadcastAs());

        $channels = $broadcast->broadcastOn();
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-branch.10', $channels[0]->name);
    }

    public function test_the_broadcast_payload_is_lean_and_carries_the_server_refs(): void
    {
        $this->device();
        $this->push($this->createEvent((string) Str::uuid()));

        $this->assertCount(1, $this->captured);
        $payload = $this->captured[0]->broadcastWith();

        $this->assertSame('order.create', $payload['type']);
        $this->assertSame(10, $payload['branch_id']);
        $this->assertIsInt($payload['event_id']);
        $this->assertSame('created', $payload['result']['status'] ?? null);
    }

    public function test_a_failed_event_does_not_broadcast(): void
    {
        $this->device();

        // Malformed order (no lines) → handler throws → event `failed`.
        $this->push($this->createEvent((string) Str::uuid(), ['lines' => []]));

        $this->assertCount(0, $this->captured);
    }

    public function test_an_unhandled_event_type_does_not_broadcast(): void
    {
        $this->device();

        // sync.noop has no handler → stays `received`, no broadcast.
        $this->push([
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'sync.noop',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['amount_baisas' => 500],
        ]);

        $this->assertCount(0, $this->captured);
    }
}
