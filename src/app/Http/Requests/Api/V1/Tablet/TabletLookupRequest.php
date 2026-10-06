<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Tablet;

use App\Http\Requests\Api\V1\PublicQr\PublicQrRequest;

/** LAUNCH-P6 — `POST device/tablet/loyalty/lookup`: { phone }. */
final class TabletLookupRequest extends PublicQrRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:32']];
    }
}
