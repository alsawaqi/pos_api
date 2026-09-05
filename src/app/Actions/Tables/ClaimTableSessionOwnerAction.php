<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use App\Models\Device;
use App\Models\TableSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Take over an unusable operational holder without changing payment lineage. */
final class ClaimTableSessionOwnerAction
{
    public function __construct(
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Device $device, string $uuid): array
    {
        $known = Str::isUuid($uuid) ? TableSession::query()
            ->where('uuid', $uuid)->where('company_id', (int) $device->company_id)
            ->where('branch_id', (int) $device->branch_id)->first() : null;
        if ($known === null) {
            throw new QrDineInException('table_session_not_found', 404, 'The seating was not found in this branch.');
        }

        // Reuse the canonical branch graph locks; this is only their internal
        // identity context, not an open event and never a seating creation.
        return $this->resolver->locked($device, [
            'seating_key' => $uuid, 'table_id' => (int) $known->table_id,
            'queued_offline' => false, 'opened_at' => now()->toIso8601String(),
        ], 'open', function (Device $caller, Collection $tables, Collection $orders, Collection $sessions, Collection $seatings) use ($uuid): array {
            $resolved = $this->resolver->resolve($seatings, $uuid, $uuid);
            $row = $resolved['row'];
            $primary = $resolved['primary'];
            if (! $this->resolver->isLive($row) || ! $this->resolver->isLive($primary)) {
                $previous = $row?->opened_by_device_id === null ? null : (int) $row->opened_by_device_id;

                return $this->present('stale_generation', $uuid, $row, $previous);
            }

            $previous = $primary->opened_by_device_id === null ? null : (int) $primary->opened_by_device_id;
            if ($previous === (int) $caller->id) {
                return $this->present('replayed', $uuid, $primary, $previous);
            }
            $holder = $previous === null ? null : Device::query()->withTrashed()->whereKey($previous)
                ->where('company_id', (int) $caller->company_id)->where('branch_id', (int) $caller->branch_id)->first();
            if ($holder !== null && ! $holder->trashed() && $holder->status === 'active' && $holder->isAssigned()
                && ($holder->isPaymentStation() || in_array($holder->device_type, ['fixed_pos', 'handheld'], true))) {
                return $this->present('owner_usable', $uuid, $primary, $previous);
            }

            $primary->update(['opened_by_device_id' => (int) $caller->id]);
            $this->journal->handle($primary, 'attached', [
                'outcome' => 'claimed', 'previous_opened_by_device_id' => $previous,
                'opened_by_device_id' => (int) $caller->id, 'owner_claim' => true,
            ], (int) $caller->id);

            return $this->present('claimed', $uuid, $primary, $previous);
        });
    }

    /** @return array<string, mixed> */
    private function present(string $outcome, string $requestedUuid, ?TableSession $seating, ?int $previous): array
    {
        return [
            'outcome' => $outcome,
            'table_session_uuid' => $seating?->uuid,
            'requested_table_session_uuid' => $requestedUuid,
            'opened_by_device_id' => $seating?->opened_by_device_id === null ? null : (int) $seating->opened_by_device_id,
            'previous_opened_by_device_id' => $previous,
        ];
    }
}
