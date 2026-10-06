<?php

declare(strict_types=1);

namespace App\Actions\Tablet;

use DomainException;
use Illuminate\Http\JsonResponse;

/** LAUNCH-P6 — a refusal on the customer tablet surfaces, with optional data. */
final class TabletOrderException extends DomainException
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public readonly string $codeName,
        public readonly int $httpStatus,
        string $message,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message);
    }

    public function response(): JsonResponse
    {
        return response()->json([
            'data' => $this->data,
            'errors' => [['code' => $this->codeName, 'message' => $this->getMessage()]],
        ], $this->httpStatus);
    }
}
