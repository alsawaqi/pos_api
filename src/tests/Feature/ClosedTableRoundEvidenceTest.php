<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\QrOrderRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class ClosedTableRoundEvidenceTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    public function test_closed_history_exposes_complete_round_evidence_without_mutating_or_leaking_credentials(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table, ['status' => 'closed']);
        $order = $this->seatingOrder($seat, ['status' => 'paid']);
        $accepted = $this->seatingRound($seat, $order, ['priced_lines' => [['order_item_id' => 321, 'product_id' => 7, 'qty' => 2, 'notes' => 'Keep exactly', 'addons' => [['add_on_id' => 9]]]]]);
        $rejected = $this->seatingRound($seat, $order, ['round_no' => 2, 'status' => 'rejected', 'needs_review' => true]);
        $pending = $this->seatingRound($seat, $order, ['round_no' => 3, 'status' => 'pending_confirmation', 'needs_review' => true]);
        $before = QrOrderRound::orderBy('id')->get()->toArray();
        $this->withToken($device->device_token)->getJson('/api/v1/device/orders/history')
            ->assertOk()->assertJsonMissingPath('data.orders.0.table_round_evidence');
        $response = $this->withToken($device->device_token)->getJson('/api/v1/device/orders/history?include_table_rounds=1')->assertOk();
        $response->assertJsonPath('data.orders.0.table_round_evidence.complete', true)
            ->assertJsonPath('data.orders.0.table_round_evidence.order_uuid', $order->uuid)
            ->assertJsonPath('data.orders.0.table_round_evidence.table_session_uuid', $seat->uuid)
            ->assertJsonPath('data.orders.0.table_round_evidence.seating_status', 'closed');
        $rounds = collect($response->json('data.orders.0.table_round_evidence.rounds'))->keyBy('id');
        $this->assertSame('accepted', $rounds[$accepted->id]['status']);
        $this->assertSame('rejected', $rounds[$rejected->id]['status']);
        $this->assertSame('pending_confirmation', $rounds[$pending->id]['status']);
        $this->assertSame($rejected->client_request_id, $rounds[$rejected->id]['client_request_id']);
        $this->assertTrue($rounds[$accepted->id]['same_seating']);
        $this->assertSame([['order_item_id' => 321, 'product_id' => 7, 'qty' => 2, 'notes' => 'Keep exactly', 'addon_ids' => [9]]], $rounds[$accepted->id]['lines']);
        $this->assertArrayNotHasKey('token', $rounds[$accepted->id]);
        $this->assertSame($before, QrOrderRound::orderBy('id')->get()->toArray());
        $device->forceFill(['branch_id' => $device->branch_id + 1])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($device->device_token)->getJson('/api/v1/device/orders/history?include_table_rounds=1')
            ->assertOk()->assertJsonCount(0, 'data.orders');
    }
}
