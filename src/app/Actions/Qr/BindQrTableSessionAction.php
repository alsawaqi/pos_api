<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Bind a station credential or admit a branch-enabled permanent table card. */
final class BindQrTableSessionAction
{
    public function __construct(
        private readonly QrTableCardEnabled $cards,
        private readonly QrScanGeofenceMode $mode,
        private readonly ScanGeofence $geofence,
        private readonly RecordQrScanAction $scans,
        private readonly AllocateQrTempReferenceAction $references,
        private readonly SupersedeAbandonedTableSessionAction $supersede,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /** @return QrSession|array<string, mixed>|null */
    public function handle(string $tableToken, string $clientSecret, array $scan = [], string $ip = ''): QrSession|array|null
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $result = DB::transaction(fn () => $this->bind(trim($tableToken), $clientSecret, $scan, $ip), 5);
                if ($result instanceof QrDineInException) {
                    // Refusals commit their tenant-scoped audit row before the
                    // controller emits the original business refusal.
                    throw $result;
                }

                return $result;
            } catch (QueryException $exception) {
                $liveConflict = str_contains($exception->getMessage(), 'pos_qr_sessions_table_live_unique')
                    || str_contains($exception->getMessage(), 'UNIQUE constraint failed: pos_qr_sessions.table_id');
                if (! $liveConflict || $attempt >= 4) {
                    throw $exception;
                }
                // Restart the entire transaction (Postgres aborts on 23505).
                // The next pass reads the winner and logs exactly one scan.
            }
        }
    }

    private function bind(string $token, string $secret, array $scan, string $ip): QrSession|array|QrDineInException|null
    {
        $table = Table::withTrashed()->where('qr_token', $token)->lockForUpdate()->first();
        if ($table === null) {
            $hash = hash('sha256', $token);
            if (Cache::add('qr:unknown-table-token:'.$hash, true, 600)) {
                Log::info('qr.table_bind.unknown_token', [
                    'token_hash_prefix' => substr($hash, 0, 12), 'ip_hash' => $this->scans->ipHash($ip),
                ]);
            }

            return null;
        }
        $floor = Floor::withTrashed()->whereKey((int) $table->floor_id)->first();
        $branch = $floor === null ? null : Branch::withTrashed()->whereKey((int) $floor->branch_id)->first();
        if ($floor === null || $branch === null || $branch->trashed()) {
            // Impossible with the production parent FKs; no tenant is invented.
            return null;
        }
        $companyId = (int) $table->company_id;
        $branchId = (int) $floor->branch_id;
        $mode = $this->mode->forBranch($companyId, $branchId);
        $geofence = $this->geofence->verdict($branch, $mode, $scan['location_state'] ?? 'not_requested', $scan['location'] ?? null);
        if ($table->trashed() || $table->status !== 'active' || $floor->trashed() || $floor->status !== 'active'
            || (int) $floor->company_id !== $companyId || (int) $branch->company_id !== $companyId) {
            $this->scans->handle($table, $branchId, null, null, 'refused', 'refused_table', $scan, $geofence, $ip);

            return null;
        }

        $now = now();
        QrSession::query()->where('table_id', $table->id)
            ->whereIn('status', QrSession::EXPIRABLE_STATUSES)->where('expires_at', '<=', $now)
            ->update(['status' => QrSession::STATUS_EXPIRED, 'closed_at' => $now, 'updated_at' => $now]);

        $live = $this->liveCredential($table);
        if ($live !== null && $live->origin === 'table_card' && ! app(TableCardIdentity::class)->valid($live)) {
            // Retire the credential only. Keep the seating and its bill intact;
            // a fresh scan can attach through the existing handover checks.
            $live->update(['status' => QrSession::STATUS_EXPIRED, 'released_at' => $now, 'closed_at' => $now]);
            $live = null;
        }
        if ($live !== null) {
            return $this->existing($table, $branchId, $live, $secret, $scan, $geofence, $ip);
        }
        if (! $this->cards->forBranch($companyId, $branchId)) {
            $this->scans->handle($table, $branchId, null, null, 'refused', 'refused_disabled', $scan, $geofence, $ip);

            return null;
        }
        if ($mode === 'enforce' && $geofence['verdict'] === 'outside') {
            $this->scans->handle($table, $branchId, null, null, 'refused', 'refused_outside', $scan, $geofence, $ip);

            return new QrDineInException('qr_scan_outside_branch', 409, 'You need to be at '.$branch->name.' to order from this table.');
        }

        $seatingQuery = TableSession::query()->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('table_id', $table->id)->whereIn('status', [TableSession::STATUS_OPEN, TableSession::STATUS_BILLING]);
        $snapshot = (clone $seatingQuery)->first();
        $bill = $snapshot?->order_id === null ? null : Order::query()->whereKey((int) $snapshot->order_id)
            ->where('company_id', $companyId)->where('branch_id', $branchId)->lockForUpdate()->first();
        $seating = (clone $seatingQuery)->lockForUpdate()->first();
        $live = $this->liveCredential($table);
        if ($live !== null && $live->origin === 'table_card' && ! app(TableCardIdentity::class)->valid($live)) {
            // Retire the credential only. Keep the seating and its bill intact;
            // a fresh scan can attach through the existing handover checks.
            $live->update(['status' => QrSession::STATUS_EXPIRED, 'released_at' => $now, 'closed_at' => $now]);
            $live = null;
        }
        if ($live !== null) {
            return $this->existing($table, $branchId, $live, $secret, $scan, $geofence, $ip);
        }
        if ($seating?->merged_into_id !== null) {
            $this->scans->handle($table, $branchId, null, (int) $seating->id, 'refused', 'refused_joined', $scan, $geofence, $ip);

            return new QrDineInException('qr_table_joined', 409, 'This table belongs to another table\'s seating.');
        }
        $this->supersede->handle($table, null, $now, $companyId, $branchId);
        $seating = (clone $seatingQuery)->lockForUpdate()->first();
        if ($seating === null) {
            $bill = null;
        }
        $released = $bill?->qr_session_id === null ? null : QrSession::query()
            ->whereKey((int) $bill->qr_session_id)->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('table_id', $table->id)->where('table_session_id', $seating?->id ?? 0)
            ->whereNotNull('released_at')->whereIn('status', [QrSession::STATUS_CLOSED, QrSession::STATUS_EXPIRED])
            ->lockForUpdate()->first();
        $allowedBillId = null;
        if ($seating !== null && $bill !== null && (int) $seating->order_id === (int) $bill->id
            && $bill->status === Order::STATUS_OPEN && ! Order::query()->whereKey($bill->id)->withLiveClaim($now)->exists()
            && (($bill->qr_session_id === null && in_array($bill->source, ['main_pos', 'handheld'], true)) || $released !== null)) {
            $allowedBillId = (int) $bill->id;
        }
        $unpaid = Order::query()->where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereIn('status', [Order::STATUS_OPEN, Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT, Order::STATUS_KITCHEN])
            ->where(static function (Builder $query) use ($table): void {
                $query->where('table_id', $table->id)->orWhereIn('id',
                    DB::table('pos_order_tables')->select('order_id')->where('table_id', $table->id));
            })->when($allowedBillId !== null, fn (Builder $query) => $query->where('id', '!=', $allowedBillId))->exists();
        if ($unpaid || ($seating?->order_id !== null && $allowedBillId === null)) {
            $this->scans->handle($table, $branchId, null, $seating?->id, 'refused', 'refused_unpaid', $scan, $geofence, $ip);

            return new QrDineInException('qr_table_has_unpaid_order', 409, 'The table still has an unpaid order.');
        }

        $horizon = $now->copy()->addHours(max(1, (int) config('qr.dine_in_session_lifetime_hours', 6)));
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId,
            'device_id' => null, 'table_id' => $table->id, 'table_session_id' => $seating?->id, 'origin' => 'table_card',
            'token' => bin2hex(random_bytes(32)), 'token_expires_at' => $horizon, 'expires_at' => $horizon,
            'status' => QrSession::STATUS_ACTIVE, 'client_secret_hash' => QrSession::hashClientSecret($secret),
            'bound_at' => $now, 'last_seen_at' => $now,
            'scan_fingerprint_hash' => $scan['fingerprint_hash'] ?? null, 'scan_ip_hash' => $this->scans->ipHash($ip),
            'scan_geofence_verdict' => $geofence['verdict'], 'handover_from_id' => $released?->id,
        ]);
        $event = 'attached';
        $outcome = $released === null ? 'attached' : 'handover';
        $payload = ['session_uuid' => (string) $session->uuid, 'card_scan' => true, 'geofence' => $geofence['verdict']];
        if ($seating === null) {
            $event = $outcome = 'opened';
            $seating = TableSession::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId,
                'table_id' => $table->id, 'status' => TableSession::STATUS_OPEN, 'origin' => TableSession::ORIGIN_TABLE_CARD,
                'opened_by_device_id' => null, 'opened_at' => $now, 'expires_at' => $horizon,
            ]);
            TableSession::query()->whereKey($seating->id)->where('company_id', $companyId)->where('branch_id', $branchId)
                ->where('table_id', $table->id)->where('status', TableSession::STATUS_OPEN)
                ->update(['temp_reference' => $this->references->handle($companyId, $branchId)]);
            $session->update(['table_session_id' => $seating->id]);
        } elseif ($released !== null) {
            // Bypass Eloquent timestamps: this is the ONLY bill column that a
            // handover may change, including updated_at and customer identity.
            DB::table('pos_orders')->where('id', $bill->id)->where('qr_session_id', $released->id)
                ->where('status', Order::STATUS_OPEN)->update(['qr_session_id' => $session->id]);
            $payload += ['adopted_order_uuid' => (string) $bill->uuid, 'handover_from' => (string) $released->uuid];
        }
        $this->journal->handle($seating, $event, $payload, null, $now);
        $this->scans->handle($table, $branchId, $session, (int) $seating->id, 'owner', $outcome, $scan, $geofence, $ip);

        return [
            'session_uuid' => $session->uuid, 'status' => $session->status,
            'expires_at' => $session->expires_at->toIso8601String(), 'read_only' => false,
            'geofence' => $geofence['verdict'], 'handover' => $released !== null,
        ];
    }

    private function liveCredential(Table $table): ?QrSession
    {
        return QrSession::query()->where('table_id', $table->id)->whereIn('status', QrSession::EXPIRABLE_STATUSES)
            ->latest('id')->lockForUpdate()->first();
    }

    private function existing(Table $table, int $branchId, QrSession $session, string $secret, array $scan, array $geofence, string $ip): QrSession|array|null
    {
        $device = Device::withTrashed()->whereKey($session->device_id)->first();
        if (! app(TableCardIdentity::class)->valid($session) || $session->released_at !== null || (int) $session->company_id !== (int) $table->company_id
            || (int) $session->branch_id !== $branchId
            || ($session->device_id === null
                ? ($session->origin !== 'table_card' || $session->table_id === null)
                : ! $this->isUsableStation($session, $device))) {
            $this->scans->handle($table, $branchId, null, $session->table_session_id, 'refused', 'refused_station', $scan, $geofence, $ip);

            return null;
        }
        if ($session->status !== QrSession::STATUS_PENDING) {
            if ($session->clientSecretMatches($secret)) {
                DB::table('pos_qr_sessions')->where('id', $session->id)->update(['last_seen_at' => now()]);
                $this->scans->handle($table, $branchId, $session, $session->table_session_id, 'owner', 'replay', $scan, $geofence, $ip);

                return [
                    'session_uuid' => $session->uuid, 'status' => $session->status,
                    'expires_at' => $session->expires_at?->toIso8601String(), 'read_only' => false,
                ];
            }
            $this->scans->handle($table, $branchId, null, $session->table_session_id, 'viewer', 'read_only', $scan, $geofence, $ip);

            return [
                'session_uuid' => null, 'status' => 'read_only', 'expires_at' => null, 'read_only' => true,
                'reason' => 'qr_table_in_use', 'table' => ['uuid' => (string) $table->uuid, 'label' => (string) $table->label],
            ];
        }
        $now = now();
        $updated = QrSession::query()->whereKey($session->id)->where('status', QrSession::STATUS_PENDING)
            ->whereNull('client_secret_hash')->where('expires_at', '>', $now)->update([
                'status' => QrSession::STATUS_ACTIVE, 'client_secret_hash' => QrSession::hashClientSecret($secret),
                'bound_at' => $now, 'last_seen_at' => $now, 'updated_at' => $now,
                'scan_fingerprint_hash' => $scan['fingerprint_hash'] ?? null,
                'scan_ip_hash' => $this->scans->ipHash($ip), 'scan_geofence_verdict' => $geofence['verdict'],
            ]);
        $this->scans->handle($table, $branchId, $session, $session->table_session_id,
            $updated === 1 ? 'owner' : 'refused', $updated === 1 ? 'first_bind' : 'refused_station', $scan, $geofence, $ip);

        return $updated === 1 ? $session->fresh() : null;
    }

    /** This is deliberately identical to the shipped public QR station gate. */
    private function isUsableStation(QrSession $session, ?Device $device): bool
    {
        return $device !== null
            && ! $device->trashed()
            && $device->status === 'active'
            && $device->isAssigned()
            && $device->isPaymentStation()
            && (int) $device->company_id === (int) $session->company_id
            && (int) $device->branch_id === (int) $session->branch_id;
    }
}
