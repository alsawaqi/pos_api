<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Tablet;

use Illuminate\Validation\Rule;

/**
 * LAUNCH-P6 — `POST device/tablet/orders`:
 *
 *   { client_uuid, order_type: dine_in|quick|to_go, table_uuid? (dine in only),
 *     lines[], phone?, payment: cash|points, redeem_request?: {rule_id, blocks} }
 *
 * `redeem_request` is sent exactly when payment is `points` (points + cash
 * for the rest) and needs a phone.
 */
final class TabletOrderRequest extends TabletLinesRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::tabletLineRules() + [
            'client_uuid' => ['required', 'uuid'],
            'table_uuid' => ['required_if:order_type,dine_in', 'prohibited_unless:order_type,dine_in', 'nullable', 'uuid'],
            'payment' => ['required', 'string', Rule::in(['cash', 'points'])],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'redeem_request' => ['required_if:payment,points', 'prohibited_unless:payment,points', 'array:rule_id,blocks'],
            'redeem_request.rule_id' => ['required_with:redeem_request', 'integer', 'min:1'],
            'redeem_request.blocks' => ['required_with:redeem_request', 'integer', 'min:1', 'max:50'],
        ];
    }
}
