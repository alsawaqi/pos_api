<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ListAcceptedDineInQrRoundsAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ClaimKitchenTicketAction;
use App\Actions\Tables\RecordKitchenPrintResultAction;
use App\Models\Device;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class KitchenTicketClaimTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_first_claim_is_201_same_holder_replays_and_competitor_is_409_without_time_lease(): void
    {
        $flow = $this->flow();
        $first = $this->postAs($flow['device'], '/api/v1/device/kitchen/claim-print', ['ticket_key' => $flow['key']])
            ->assertCreated()->assertJsonPath('data.replayed', false);
        $first->assertJsonPath('data.ticket_key', $flow['key'])
            ->assertJsonPath('data.printed_at', null)->assertJsonPath('data.print_result', null)
            ->assertJsonPath('data.round_id', (int) $flow['round']->id)
            ->assertJsonPath('data.order_uuid', $flow['order']->uuid);
        $this->assertNotNull($first->json('data.event_id'));
        $this->assertSame(1, KitchenTicket::query()->count());
        $this->assertSame(['print_claimed'], TableSessionEvent::query()->pluck('event_type')->all());
        $before = KitchenTicket::query()->sole()->getRawOriginal();
        $this->travel(2)->days();
        $this->postAs($flow['device'], '/api/v1/device/kitchen/claim-print', ['ticket_key' => $flow['key']])
            ->assertCreated()->assertJsonPath('data.replayed', true)->assertJsonPath('data.event_id', null);
        $this->postAs($this->seatingDevice('handheld'), '/api/v1/device/kitchen/claim-print', ['ticket_key' => $flow['key']])
            ->assertConflict()->assertJsonPath('errors.0.code', 'kitchen_ticket_claimed');
        $this->assertSame($before, KitchenTicket::query()->sole()->getRawOriginal());
        $this->assertNull($flow['round']->fresh()->kitchen_printed_at);
        $this->assertSame(1, TableSessionEvent::query()->count());
        fwrite(STDOUT, "\nT4_KITCHEN_CLAIM_JSON=".$first->getContent()."\n");
    }

    public function test_failed_ticket_is_reclaimable_and_old_holder_cannot_report_after_takeover(): void
    {
        $flow = $this->flow(['needs_review' => true]);
        $other = $this->seatingDevice('handheld');
        $this->claim($flow);
        $failed = $this->record($flow, 'failed');
        $this->assertSame('failed', $failed['print_result']);
        $this->assertNull($failed['printed_at']);
        $this->assertNull($flow['round']->fresh()->kitchen_printed_at);
        $this->assertTrue($failed['print_pending']);
        $takeover = app(ClaimKitchenTicketAction::class)->handle($other, ['ticket_key' => $flow['key']]);
        $this->assertSame((int) $other->id, $takeover['claimed_by_device_id']);
        $this->assertFalse($takeover['replayed']);
        $this->assertNull($takeover['print_result']);
        $this->assertSame(1, KitchenTicket::query()->count());
        $before = KitchenTicket::query()->sole()->getRawOriginal();
        $this->assertRefused('kitchen_ticket_claimed', fn () => $this->record($flow, 'printed', now()->toIso8601String()));
        $this->assertSame($before, KitchenTicket::query()->sole()->getRawOriginal());
        $printedAt = now()->subSeconds(23)->toIso8601String();
        $printed = app(RecordKitchenPrintResultAction::class)->handle($other, [
            'ticket_key' => $flow['key'], 'print_result' => 'printed', 'printed_at' => $printedAt,
        ]);
        $this->assertSame($printedAt, $printed['printed_at']);
        $this->assertSame('printed', $printed['print_result']);
        $this->assertFalse($printed['print_pending']);
        $this->assertSame($printedAt, Carbon::parse($flow['round']->fresh()->kitchen_printed_at)->toIso8601String());
        $this->assertSame(['print_claimed', 'print_result', 'print_claimed', 'print_result'],
            TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all());
        fwrite(STDOUT, "\nT4_KITCHEN_RESULT_JSON=".json_encode($printed, JSON_THROW_ON_ERROR)."\n");
    }

    public function test_same_holder_can_retry_failed_claim_and_success_cannot_be_downgraded_or_restamped(): void
    {
        $flow = $this->flow();
        $this->claim($flow);
        $this->record($flow, 'failed');
        $failureEvents = TableSessionEvent::query()->count();
        $failureReplay = $this->record($flow, 'failed');
        $this->assertTrue($failureReplay['replayed']);
        $this->assertNull($failureReplay['event_id']);
        $this->assertSame($failureEvents, TableSessionEvent::query()->count());
        $this->travel(1)->minutes();
        $reclaimed = $this->claim($flow);
        $this->assertFalse($reclaimed['replayed']);
        $this->assertNull($reclaimed['print_result']);
        $this->assertSame(now()->toIso8601String(), $reclaimed['claimed_at']);
        $printed = $this->record($flow, 'printed', now()->toIso8601String());
        $before = KitchenTicket::query()->sole()->getRawOriginal();
        $roundBefore = $flow['round']->fresh()->getRawOriginal();
        $eventCount = TableSessionEvent::query()->count();
        $this->travel(1)->minutes();
        foreach (['failed', 'printed'] as $result) {
            $replay = $this->record($flow, $result, $result === 'printed' ? now()->toIso8601String() : null);
            $this->assertTrue($replay['replayed']);
            $this->assertSame($printed['printed_at'], $replay['printed_at']);
            $this->assertSame('printed', $replay['print_result']);
            $this->assertNull($replay['event_id']);
        }
        $this->assertSame($before, KitchenTicket::query()->sole()->getRawOriginal());
        $this->assertSame($roundBefore, $flow['round']->fresh()->getRawOriginal());
        $this->assertSame($eventCount, TableSessionEvent::query()->count());
    }

    /** @return array<string, array{string, bool, bool, ?int, bool}> */
    public static function printMatrix(): array
    {
        return [
            'ordinary accepted' => ['accepted', false, false, null, true],
            'merged pending with evidence' => ['pending_confirmation', true, false, -30, true],
            'merged accepted with evidence' => ['accepted', true, false, -30, true],
            'merged pending without evidence' => ['pending_confirmation', true, false, null, false],
            'merged accepted without evidence' => ['accepted', true, false, null, true],
            'merged rejected' => ['rejected', true, false, -30, false],
            'held pending without evidence' => ['pending_confirmation', false, true, null, false],
            'held pending with evidence' => ['pending_confirmation', false, true, -30, false],
            'merged held pending with evidence' => ['pending_confirmation', true, true, -30, false],
            'held accepted subset' => ['accepted', false, true, null, true],
            'held rejected' => ['rejected', false, true, -30, false],
            'merged pending future evidence' => ['pending_confirmation', true, false, 301, false],
            'merged pending permitted skew' => ['pending_confirmation', true, false, 300, true],
            'ordinary pending even with evidence' => ['pending_confirmation', false, false, -30, false],
        ];
    }

    #[DataProvider('printMatrix')]
    public function test_print_matrix(
        string $status, bool $merged, bool $held, ?int $evidenceSeconds, bool $allowed,
    ): void {
        $lines = [['product_id' => 1001, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]];
        if ($held) {
            $lines[] = [
                'line_index' => 1, 'product_id' => 1002, 'qty' => 2, 'requested' => true,
                'held_reason' => 'inactive', 'unit_price_baisas' => null, 'line_total_baisas' => null,
                'held_disposition' => $status === 'accepted' ? 'dropped_at_review' : null,
            ];
        }
        $flow = $this->flow([
            'status' => $status, 'needs_review' => $held || $merged, 'priced_lines' => $lines,
            'kitchen_printed_at' => $evidenceSeconds === null ? null : now()->addSeconds($evidenceSeconds),
        ]);
        if ($merged) {
            $alias = TableSession::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
                'table_id' => $flow['seating']->table_id, 'status' => TableSession::STATUS_MERGED,
                'origin' => TableSession::ORIGIN_STAFF_TILL, 'client_request_id' => (string) Str::uuid(),
                'merged_into_id' => $flow['seating']->id, 'opened_at' => now()->subMinute(),
                'expires_at' => now()->addHours(6),
                'close_reason' => 'merged', 'closed_at' => now(),
            ]);
            $flow['round']->update(['origin_table_session_id' => $alias->id]);
        }
        $before = $flow['round']->fresh()->getRawOriginal();
        if ($allowed) {
            $claim = $this->claim($flow);
            $this->assertFalse($claim['replayed']);
            $this->assertCount(1, $claim['priced_lines']);
            $this->assertSame($lines[0], $claim['priced_lines'][0]);
            $this->assertSame(1, KitchenTicket::query()->count());
            $this->assertSame(1, TableSessionEvent::query()->count());
        } else {
            $this->assertRefused('kitchen_round_not_printable', fn () => $this->claim($flow));
            $this->assertSame(0, KitchenTicket::query()->count());
            $this->assertSame(0, TableSessionEvent::query()->count());
        }
        $this->assertSame($before, $flow['round']->fresh()->getRawOriginal());
        fwrite(STDOUT, "\nT4_PRINT_MATRIX=".json_encode([
            'status' => $status, 'merged' => $merged, 'held' => $held,
            'evidence_seconds' => $evidenceSeconds, 'claim_allowed' => $allowed,
            'ticket_count' => KitchenTicket::query()->count(),
        ], JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<string, array{bool}> */
    public static function legacyTablePresence(): array
    {
        return ['physical table linked' => [true], 'physical table links already null' => [false]];
    }

    #[DataProvider('legacyTablePresence')]
    public function test_legacy_paid_qr_round_without_seating_remains_claimable_and_does_not_create_one(bool $tableLinked): void
    {
        $device = $this->seatingDevice();
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $station->id, 'table_id' => $tableLinked ? $table->id : null, 'status' => QrSession::STATUS_CLOSED,
            'token' => hash('sha256', (string) Str::uuid()), 'token_expires_at' => now()->subHour(),
            'expires_at' => now()->subHour(), 'closed_at' => now()->subMinute(),
        ]);
        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $station->id, 'qr_session_id' => $session->id, 'table_id' => $tableLinked ? $table->id : null,
            'source' => 'qr_web', 'order_type' => 'dine_in', 'status' => Order::STATUS_PAID,
            'subtotal' => '1.000', 'discount_total' => '0.000', 'comp_total' => '0.000',
            'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now()->subHour(),
        ]);
        $round = QrOrderRound::query()->create([
            'qr_session_id' => $session->id, 'order_id' => $order->id, 'round_no' => 1,
            'status' => QrOrderRound::STATUS_ACCEPTED, 'client_request_id' => 'legacy-accepted',
            'priced_lines' => [['product_id' => 1, 'qty' => 1, 'line_total_baisas' => 1000]],
            'subtotal_baisas' => 1000, 'tax_baisas' => 0, 'total_baisas' => 1000,
            'submitted_at' => now()->subHour(), 'resolved_at' => now()->subHour(), 'accepted_seq' => 1,
        ]);
        $flow = ['device' => $device, 'round' => $round, 'key' => 'round:'.$round->id];
        $this->assertSame([(int) $round->id], array_column(
            app(ListAcceptedDineInQrRoundsAction::class)->handle($device)['rounds'], 'id',
        ), 'Every legacy row still offered by the accepted feed must remain claimable.');
        $claim = $this->claim($flow);
        $this->assertNull($claim['event_id']);
        $printed = $this->record($flow, 'printed', now()->subSeconds(5)->toIso8601String());
        $this->assertNull($printed['event_id']);
        $this->assertSame('printed', $printed['print_result']);
        $this->assertSame(0, TableSession::query()->count());
        $this->assertSame(0, TableSessionEvent::query()->count());
        $this->assertNull($order->fresh()->table_session_id);
        $this->assertNull($round->fresh()->table_session_id);
        $this->assertNull($session->fresh()->table_session_id);
        $this->assertSame($tableLinked ? (int) $table->id : null, $session->fresh()->table_id);
        $this->assertSame($tableLinked ? (int) $table->id : null, $order->fresh()->table_id);
    }

    public function test_cross_branch_and_company_keys_are_not_found_and_non_attended_devices_are_refused(): void
    {
        $flow = $this->flow();
        foreach ([$this->seatingDevice('fixed_pos', 11), $this->seatingDevice('fixed_pos', 20, 200)] as $foreign) {
            $this->assertRefused('kitchen_round_not_found', fn () => app(ClaimKitchenTicketAction::class)
                ->handle($foreign, ['ticket_key' => $flow['key']]));
        }
        $station = $this->seatingDevice('payment_station');
        $this->assertRefused('device_not_attended', fn () => app(ClaimKitchenTicketAction::class)
            ->handle($station, ['ticket_key' => $flow['key']]));
        $flow['device']->update(['status' => 'inactive']);
        $this->assertRefused('device_unassigned', fn () => $this->claim($flow));
        $this->assertSame(0, KitchenTicket::query()->count());
        $this->assertSame(0, TableSessionEvent::query()->count());
    }

    public function test_result_requires_current_claim_and_explicit_valid_printed_timestamp(): void
    {
        $flow = $this->flow();
        $this->assertRefused('kitchen_ticket_not_found', fn () => $this->record($flow, 'failed'));
        $this->claim($flow);
        foreach ([
            ['ticket_key' => $flow['key'], 'print_result' => 'printed'],
            ['ticket_key' => $flow['key'], 'print_result' => 'printed', 'printed_at' => null],
            ['ticket_key' => $flow['key'], 'print_result' => 'printed', 'printed_at' => 'not-a-date'],
            ['ticket_key' => $flow['key'], 'print_result' => 'printed', 'printed_at' => now()->addSeconds(301)->toIso8601String()],
            ['ticket_key' => $flow['key'], 'print_result' => 'attempted'],
        ] as $payload) {
            $this->postAs($flow['device'], '/api/v1/device/kitchen/print-result', $payload)
                ->assertUnprocessable()->assertJsonPath('errors.0.code', 'validation_failed');
            $this->assertNull(KitchenTicket::query()->sole()->printed_at);
            $this->assertNull(KitchenTicket::query()->sole()->print_result);
            $this->assertNull($flow['round']->fresh()->kitchen_printed_at);
        }
        $at = now()->addSeconds(300)->toIso8601String();
        $this->postAs($flow['device'], '/api/v1/device/kitchen/print-result', [
            'ticket_key' => $flow['key'], 'print_result' => 'printed', 'printed_at' => $at,
        ])->assertOk()->assertJsonPath('data.printed_at', $at);
        $this->assertSame($at, KitchenTicket::query()->sole()->printed_at->toIso8601String());
    }

    public function test_malformed_keys_and_anonymous_requests_do_not_claim_anything(): void
    {
        $flow = $this->flow();
        $this->postJson('/api/v1/device/kitchen/claim-print', ['ticket_key' => $flow['key']])->assertUnauthorized();
        foreach (['1', 'round:0', 'round:-1', 'round:1 OR 1=1', 'round:01', 'round:1 '.Str::random(100)] as $key) {
            $this->postAs($flow['device'], '/api/v1/device/kitchen/claim-print', ['ticket_key' => $key])
                ->assertUnprocessable()->assertJsonPath('errors.0.code', 'validation_failed');
        }
        $this->assertSame(0, KitchenTicket::query()->count());
        $this->assertSame(0, TableSessionEvent::query()->count());
    }

    public function test_outer_rollback_keeps_ticket_round_evidence_and_journal_atomic(): void
    {
        $flow = $this->flow();
        try {
            DB::transaction(function () use ($flow): void {
                $this->claim($flow);
                $this->record($flow, 'printed', now()->toIso8601String());
                throw new RuntimeException('rollback-print-evidence');
            });
            $this->fail('The forced transaction rollback must execute.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback-print-evidence', $exception->getMessage());
        }
        $this->assertSame(0, KitchenTicket::query()->count());
        $this->assertSame(0, TableSessionEvent::query()->count());
        $this->assertNull($flow['round']->fresh()->kitchen_printed_at);
    }

    public function test_accepted_feed_widens_additively_with_claim_and_result_without_changing_round_values(): void
    {
        $flow = $this->flow(['accepted_seq' => 1, 'needs_review' => false]);
        $before = app(ListAcceptedDineInQrRoundsAction::class)->handle($flow['device'])['rounds'];
        $this->assertCount(1, $before);
        $this->assertSame($flow['key'], $before[0]['ticket_key']);
        $this->assertSame($flow['seating']->uuid, $before[0]['table_session_uuid']);
        $this->assertSame('main_pos', $before[0]['source']);
        $this->assertNull($before[0]['session_uuid']);
        $this->assertNull($before[0]['claimed_by_device_id']);
        $this->assertNull($before[0]['printed_at']);
        $this->assertFalse($before[0]['needs_review']);
        $this->claim($flow);
        $expected = $before[0];
        $expected['claimed_by_device_id'] = (int) $flow['device']->id;
        $claimed = app(ListAcceptedDineInQrRoundsAction::class)->handle($flow['device'])['rounds'];
        $this->assertSame([$expected], $claimed);
        $at = now()->subSeconds(11)->toIso8601String();
        $this->record($flow, 'printed', $at);
        $expected['printed_at'] = $at;
        $printed = app(ListAcceptedDineInQrRoundsAction::class)->handle($flow['device'])['rounds'];
        $this->assertSame([$expected], $printed);
        fwrite(STDOUT, "\nT4_ACCEPTED_KITCHEN_FEED_JSON=".json_encode($printed, JSON_THROW_ON_ERROR)."\n");
    }

    public function test_reviewed_round_never_enters_automatic_feed_before_or_after_explicit_print(): void
    {
        $flow = $this->flow([
            'accepted_seq' => 1, 'needs_review' => true,
            'priced_lines' => [
                ['line_index' => 0, 'product_id' => 1, 'qty' => 1, 'line_total_baisas' => 1000],
                ['line_index' => 1, 'product_id' => 2, 'qty' => 1, 'held_reason' => 'inactive',
                    'held_disposition' => 'dropped_at_review', 'line_total_baisas' => null],
            ],
        ]);
        $this->assertSame([], app(ListAcceptedDineInQrRoundsAction::class)->handle($flow['device'])['rounds']);
        $claim = $this->claim($flow);
        $this->assertTrue($claim['print_pending']);
        $this->assertCount(1, $claim['priced_lines']);
        $this->assertSame(1, $claim['priced_lines'][0]['product_id']);
        $this->assertSame([], app(ListAcceptedDineInQrRoundsAction::class)->handle($flow['device'])['rounds']);
        $printed = $this->record($flow, 'printed', now()->toIso8601String());
        $this->assertFalse($printed['print_pending']);
        $this->assertSame([], app(ListAcceptedDineInQrRoundsAction::class)->handle($flow['device'])['rounds']);
        $this->assertTrue((bool) $flow['round']->fresh()->needs_review);
        $this->assertCount(2, $flow['round']->fresh()->priced_lines);
    }

    public function test_inconsistent_existing_ticket_cannot_be_rebound_to_a_different_bill(): void
    {
        $flow = $this->flow();
        $other = $this->flow();
        $ticket = KitchenTicket::query()->create([
            'company_id' => 100, 'branch_id' => 10, 'ticket_key' => $flow['key'],
            'round_id' => $other['round']->id, 'order_id' => $other['order']->id,
            'claimed_by_device_id' => $flow['device']->id, 'claimed_at' => now(), 'print_result' => 'failed',
        ]);
        $before = $ticket->fresh()->getRawOriginal();
        $this->assertRefused('kitchen_round_not_found', fn () => $this->claim($flow));
        $this->assertRefused('kitchen_round_not_found', fn () => $this->record($flow, 'printed', now()->toIso8601String()));
        $this->assertSame($before, $ticket->fresh()->getRawOriginal());
        $this->assertSame(0, TableSessionEvent::query()->count());
        $this->assertNull($flow['round']->fresh()->kitchen_printed_at);
        $this->assertNull($other['round']->fresh()->kitchen_printed_at);
    }

    public function test_print_admission_uses_the_same_known_order_sources_as_the_accepted_feed(): void
    {
        $flow = $this->flow(['accepted_seq' => 1, 'needs_review' => false]);
        foreach (['customer_tablet', 'future_source'] as $source) {
            $flow['order']->update(['source' => $source]);
            $this->assertSame([], app(ListAcceptedDineInQrRoundsAction::class)->handle($flow['device'])['rounds']);
            $this->assertRefused('kitchen_round_not_found', fn () => $this->claim($flow));
        }
        $this->assertSame(0, KitchenTicket::query()->count());
        $this->assertSame(0, TableSessionEvent::query()->count());
        $this->assertNull($flow['round']->fresh()->kitchen_printed_at);
    }

    private function flow(array $roundAttributes = []): array
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seating = $this->seatingRow($table, ['opened_by_device_id' => $device->id]);
        $order = $this->seatingOrder($seating);
        $round = $this->seatingRound($seating, $order, $roundAttributes);
        $key = 'round:'.$round->id;

        return compact('device', 'table', 'seating', 'order', 'round', 'key');
    }

    private function claim(array $flow): array
    {
        return app(ClaimKitchenTicketAction::class)->handle($flow['device'], ['ticket_key' => $flow['key']]);
    }

    private function record(array $flow, string $result, ?string $at = null): array
    {
        return app(RecordKitchenPrintResultAction::class)->handle($flow['device'], [
            'ticket_key' => $flow['key'], 'print_result' => $result, 'printed_at' => $at,
        ]);
    }

    private function assertRefused(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected refusal '.$code);
        } catch (QrDineInException $exception) {
            $this->assertSame($code, $exception->codeName);
        }
    }

    private function postAs(Device $device, string $path, array $payload): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->plainTextToken)->postJson($path, $payload);
    }
}
