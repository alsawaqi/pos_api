<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use Closure;
use Illuminate\Support\Facades\Validator;

/** Closed ID-only adjustment contract, shared by HTTP and sync. */
final class TableAdjustmentIntent
{
    public static function rules(): array
    {
        return [
            '*' => [static function (string $attribute, mixed $value, Closure $fail): void {
                if (! in_array($attribute, ['table_id', 'seating_key', 'queued_offline', 'staff_id',
                    'client_request_id', 'client_timestamp', 'adjustment'], true)) {
                    $fail('Unexpected adjustment field.');
                }
            }],
            'client_request_id' => ['required', 'uuid'],
            'adjustment' => ['required', 'array', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_array($value)) {
                    return;
                }
                $rules = match (($value['kind'] ?? '').':'.($value['mode'] ?? '')) {
                    'discount:percent' => ['percent_bp' => 'required|integer|min:1|max:10000',
                        'label' => 'required|string|max:255', 'reason' => 'sometimes|nullable|string|max:160'],
                    'discount:fixed' => ['amount_baisas' => 'required|integer|min:1|max:999999999999',
                        'label' => 'required|string|max:255', 'reason' => 'sometimes|nullable|string|max:160'],
                    'discount:rule' => ['discount_id' => 'required|integer|min:1',
                        'authorized_by' => 'sometimes|nullable|string|max:100', 'approved_by_staff_id' => 'sometimes|nullable|integer|min:1'],
                    'comp:apply' => ['comp_reason_id' => 'required|integer|min:1', 'target' => 'required',
                        'note' => 'sometimes|nullable|string|max:2000', 'authorized_by' => 'sometimes|nullable|string|max:100',
                        'approved_by_staff_id' => 'sometimes|nullable|integer|min:1'],
                    'customer:attach' => ['customer_id' => 'required|integer|min:1'],
                    'discount:clear', 'comp:clear', 'customer:detach' => [],
                    default => null,
                };
                if ($rules === null || array_diff(array_keys($value), ['kind', 'mode', ...array_keys($rules)]) !== []
                    || Validator::make($value, $rules)->fails()) {
                    $fail('Invalid adjustment intent; send only the permitted identifiers and values.');

                    return;
                }
                if (($value['kind'] ?? '') === 'comp' && ($value['mode'] ?? '') === 'apply' && $value['target'] !== 'bill') {
                    $target = $value['target'];
                    if (! is_array($target) || array_diff(array_keys($target), ['order_item_id', 'qty']) !== []
                        || Validator::make($target, ['order_item_id' => 'required|integer|min:1', 'qty' => 'required|integer|min:1|max:999'])->fails()) {
                        $fail('Select a frozen bill line and quantity.');
                    }
                }
            }],
        ];
    }
}
