<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\StaffMessage;
use App\Models\StaffMessageRead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P-G6 — staff announcements on the device pipeline.
 *
 *   - /device/config carries a staff_messages slice: company-wide +
 *     this-branch + this-branch's-staff targets only (another branch's
 *     audience never rides along), with the read_staff_ids receipt set;
 *   - POST /device/messages/read writes idempotent receipts, touch()es
 *     the message (delta resurfacing) and skips foreign ids;
 *   - retracted (soft-deleted) announcements surface in
 *     deleted.staff_messages on a delta.
 *
 * Catalogue: company 100 — branch 10 (the device's) with staff Alice
 * (id 1), branch 20 with staff Bob (id 2).
 */
class DeviceMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const ALICE = 1;

    private const BOB = 2;

    private function device(string $token = 'mdev_msg'): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => 100, 'branch_id' => 10]);
    }

    private function seedStaff(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_staff')->insert([
            ['id' => self::ALICE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Alice', 'position' => 'cashier', 'status' => 'active', 'pin_hash' => 'x'] + $t,
            ['id' => self::BOB, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 20, 'name' => 'Bob', 'position' => 'cashier', 'status' => 'active', 'pin_hash' => 'x'] + $t,
        ]);
    }

    private function message(array $attributes): StaffMessage
    {
        return StaffMessage::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'target_type' => StaffMessage::TARGET_COMPANY,
            'body' => 'Hello team',
            'created_by_name' => 'The Boss',
            ...$attributes,
        ]);
    }

    public function test_config_carries_only_this_branch_audience(): void
    {
        $this->seedStaff();
        $this->device();

        $company = $this->message(['title' => 'All hands']);
        $thisBranch = $this->message(['target_type' => StaffMessage::TARGET_BRANCH, 'target_branch_id' => 10]);
        $otherBranch = $this->message(['target_type' => StaffMessage::TARGET_BRANCH, 'target_branch_id' => 20]);
        $forAlice = $this->message(['target_type' => StaffMessage::TARGET_STAFF, 'target_staff_id' => self::ALICE]);
        $forBob = $this->message(['target_type' => StaffMessage::TARGET_STAFF, 'target_staff_id' => self::BOB]);

        $data = $this->withToken('mdev_msg')->getJson('/api/v1/device/config')->assertOk()->json('data');

        $ids = collect($data['staff_messages'])->pluck('id')->all();
        $this->assertContains($company->id, $ids);
        $this->assertContains($thisBranch->id, $ids);
        $this->assertContains($forAlice->id, $ids);
        $this->assertNotContains($otherBranch->id, $ids);
        $this->assertNotContains($forBob->id, $ids);

        $row = collect($data['staff_messages'])->firstWhere('id', $company->id);
        $this->assertSame('All hands', $row['title']);
        $this->assertSame('The Boss', $row['created_by_name']);
        $this->assertSame([], $row['read_staff_ids']);
    }

    public function test_marking_read_is_idempotent_and_resurfaces_in_deltas(): void
    {
        $this->seedStaff();
        $this->device();
        $message = $this->message([]);

        // Cursor BEFORE the read — the message itself predates it.
        $message->forceFill(['created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)])->save();
        $cursor = now()->subMinutes(5)->toIso8601String();

        $res = $this->withToken('mdev_msg')->postJson('/api/v1/device/messages/read', [
            'staff_id' => self::ALICE,
            'message_ids' => [$message->id],
        ])->assertOk();
        $this->assertSame(1, $res->json('data.marked'));

        // Re-marking is a no-op.
        $again = $this->withToken('mdev_msg')->postJson('/api/v1/device/messages/read', [
            'staff_id' => self::ALICE,
            'message_ids' => [$message->id],
        ])->assertOk();
        $this->assertSame(0, $again->json('data.marked'));
        $this->assertSame(1, StaffMessageRead::query()->count());

        // The receipt touched the message, so a delta from the pre-read
        // cursor resurfaces it WITH the read-set (how till B's badge for
        // Alice clears).
        $delta = $this->withToken('mdev_msg')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($cursor))
            ->assertOk()->json('data');
        $row = collect($delta['staff_messages'])->firstWhere('id', $message->id);
        $this->assertNotNull($row);
        $this->assertSame([self::ALICE], $row['read_staff_ids']);
    }

    public function test_read_skips_foreign_messages_and_rejects_unknown_staff(): void
    {
        $this->seedStaff();
        $this->device();
        $foreign = StaffMessage::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 999,
            'target_type' => StaffMessage::TARGET_COMPANY,
            'body' => 'Other tenant',
        ]);

        // Foreign message id: silently skipped, nothing marked.
        $res = $this->withToken('mdev_msg')->postJson('/api/v1/device/messages/read', [
            'staff_id' => self::ALICE,
            'message_ids' => [$foreign->id],
        ])->assertOk();
        $this->assertSame(0, $res->json('data.marked'));
        $this->assertSame(0, StaffMessageRead::query()->count());

        // Unknown staff id: 422.
        $this->withToken('mdev_msg')->postJson('/api/v1/device/messages/read', [
            'staff_id' => 777,
            'message_ids' => [$foreign->id],
        ])->assertStatus(422);
    }

    public function test_read_rejects_another_branchs_staff_and_out_of_audience_messages(): void
    {
        $this->seedStaff();
        $this->device();
        $otherBranch = $this->message(['target_type' => StaffMessage::TARGET_BRANCH, 'target_branch_id' => 20]);
        $forBob = $this->message(['target_type' => StaffMessage::TARGET_STAFF, 'target_staff_id' => self::BOB]);
        $forAlice = $this->message(['target_type' => StaffMessage::TARGET_STAFF, 'target_staff_id' => self::ALICE]);

        // Bob is real and active but belongs to branch 20 — this branch-10
        // device cannot write receipts on his behalf (the audit surface
        // would be forgeable across branches otherwise).
        $this->withToken('mdev_msg')->postJson('/api/v1/device/messages/read', [
            'staff_id' => self::BOB,
            'message_ids' => [$forBob->id],
        ])->assertStatus(422);

        // Same-company messages OUTSIDE this device's config audience
        // (another branch's announcement, another staff member's private
        // message) are silently skipped; the in-audience one is marked.
        $res = $this->withToken('mdev_msg')->postJson('/api/v1/device/messages/read', [
            'staff_id' => self::ALICE,
            'message_ids' => [$otherBranch->id, $forBob->id, $forAlice->id],
        ])->assertOk();
        $this->assertSame(1, $res->json('data.marked'));
        $this->assertSame(
            [$forAlice->id],
            StaffMessageRead::query()->pluck('staff_message_id')->all(),
        );
    }

    public function test_retracted_announcements_surface_in_the_deleted_map(): void
    {
        $this->seedStaff();
        $this->device();
        $message = $this->message([]);
        $message->forceFill(['created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)])->save();
        $cursor = now()->subMinutes(5)->toIso8601String();

        $message->delete();

        $delta = $this->withToken('mdev_msg')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($cursor))
            ->assertOk()->json('data');
        $this->assertContains($message->id, $delta['deleted']['staff_messages']);
        // And it no longer rides the changed slice.
        $this->assertNull(collect($delta['staff_messages'])->firstWhere('id', $message->id));
    }
}
