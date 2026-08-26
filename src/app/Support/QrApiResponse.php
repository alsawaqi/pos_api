<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/** Stable public QR envelope; it deliberately exposes no validation detail. */
final class QrApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function success(array $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $meta === [] ? (object) [] : $meta,
            'errors' => [],
        ], $status);
    }

    public static function failure(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'data' => null,
            'errors' => [[
                'code' => $code,
                'message' => $message,
            ]],
        ], $status);
    }
}
