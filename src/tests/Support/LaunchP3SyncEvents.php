<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * LAUNCH-P3 fix order 1 — device sync events for the Part B tests: an
 * order.create / order.hold built the way the till (client_timestamp =
 * opened_at = the action time) or the handheld (opened_at kept, the
 * client_timestamp passed in) builds it, and a cash order.pay.
 */
trait LaunchP3SyncEvents
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra  extra event keys (e.g. identity)
     * @return array<string, mixed>
     */
    protected function orderEvent(string $type, string $uuid, Carbon|string $clientAt, array $lines, Carbon|string|null $openedAt = null, array $extra = []): array
    {
        $total = array_sum(array_column($lines, 'line_total_baisas'));
        $client = is_string($clientAt) ? $clientAt : $clientAt->copy()->utc()->toIso8601String();
        $opened = $openedAt === null ? $client : (is_string($openedAt) ? $openedAt : $openedAt->copy()->utc()->toIso8601String());

        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'client_timestamp' => $client,
            'payload' => ['order' => [
                'uuid' => $uuid, 'order_type' => 'dine_in', 'source' => 'main_pos', 'staff_id' => 7,
                'opened_at' => $opened,
                'subtotal_baisas' => $total, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => $total,
                'lines' => $lines,
            ]],
        ] + $extra;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function payEvent(string $uuid, Carbon|string $at, int $baisas, array $extra = []): array
    {
        $stamp = is_string($at) ? $at : $at->copy()->utc()->toIso8601String();

        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => $stamp,
            'payload' => ['order_uuid' => $uuid, 'paid_at' => $stamp,
                'payments' => [['method' => 'cash', 'amount_baisas' => $baisas, 'change_given_baisas' => 0]]],
        ] + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(int $productId, int|float $qty = 1, int $priceBaisas = 1500, array $addonIds = []): array
    {
        return ['product_id' => $productId, 'qty' => $qty, 'unit_price_baisas' => $priceBaisas, 'line_total_baisas' => (int) round($priceBaisas * $qty)]
            + ($addonIds === [] ? [] : ['addons' => array_map(static fn (int $id): array => ['add_on_id' => $id, 'price_delta_baisas' => 0], $addonIds)]);
    }

    /**
     * Push and expect every event processed.
     *
     * @param  list<array<string, mixed>>  $events
     */
    protected function pushProcessed(string $token, array $events): TestResponse
    {
        $response = $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events])->assertOk();
        foreach ($response->json('data.results') as $result) {
            $this->assertSame('processed', $result['status'], (string) json_encode($result));
        }

        return $response;
    }

    /**
     * The quantity of $ingredientId each line of the order copied per unit,
     * in line order (null = the line copied no recipe).
     *
     * @return list<float|null>
     */
    protected function copiedQty(string $uuid, int $ingredientId): array
    {
        return OrderItem::query()
            ->whereHas('order', static fn ($q) => $q->where('uuid', $uuid))
            ->orderBy('id')
            ->get()
            ->map(static function (OrderItem $item) use ($ingredientId): ?float {
                if ($item->recipe_snapshot_json === null) {
                    return null;
                }
                foreach ($item->recipe_snapshot_json as $line) {
                    if ((int) $line['ingredient_id'] === $ingredientId) {
                        return (float) $line['qty'];
                    }
                }

                return 0.0;
            })
            ->all();
    }
}
