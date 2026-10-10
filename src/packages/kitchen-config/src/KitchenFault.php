<?php

declare(strict_types=1);

namespace App\Kitchen;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class KitchenFault extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 409, public readonly array $detail = [])
    {
        parent::__construct($reason);
    }

    public function render(): JsonResponse
    {
        return response()->json(['data' => $this->detail, 'errors' => [['code' => $this->reason, 'message' => str_replace('_', ' ', $this->reason), 'retryable' => false]]], $this->status);
    }

    public static function require(bool $condition, string $reason, int $status = 409, array $detail = []): void
    {
        if (! $condition) {
            throw new self($reason, $status, $detail);
        }
    }
}
