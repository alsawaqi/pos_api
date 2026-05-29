<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\Device;
use App\Models\Expense;
use App\Models\SyncEvent;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 8.8 — processes an `expense.log` sync event into a pos_expenses row
 * (blueprint §5.10). Company/branch from the device; the operator who logged
 * it is recorded as logged_by_pos_staff_id. Lands status=recorded for the
 * merchant portal's review queue. Money is wire baisas → decimal OMR.
 */
class ExpenseLogHandler implements SyncEventHandler
{
    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;

        $validator = Validator::make($payload, [
            'category' => ['required', 'string', 'in:'.implode(',', Expense::CATEGORIES)],
            'amount_baisas' => ['required', 'integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string'],
            'receipt_photo_path' => ['sometimes', 'nullable', 'string'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('invalid expense.log payload: '.implode('; ', $validator->errors()->all()));
        }

        $expense = Expense::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'category' => $payload['category'],
            'amount' => Money::toOmr((int) $payload['amount_baisas']),
            'note' => $payload['note'] ?? null,
            'receipt_photo_path' => $payload['receipt_photo_path'] ?? null,
            'logged_by_pos_staff_id' => $payload['staff_id'] ?? null,
            'logged_at' => isset($payload['logged_at']) ? Carbon::parse((string) $payload['logged_at']) : now(),
            'status' => Expense::STATUS_RECORDED,
        ]);

        return ['expense_id' => (int) $expense->id, 'status' => 'recorded'];
    }
}
