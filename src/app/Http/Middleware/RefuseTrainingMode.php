<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LAUNCH-P5 (A8) — the training-mode safety net. A device in training mode
 * keeps everything local; anything that still reaches a device endpoint with
 * `training: true` (body or query) is refused before it can touch the books:
 *
 *   403 { data: null, errors: [{ code: "training_refused", message }] }
 *
 * Sync events carrying it are refused per event by IngestSyncEventsAction.
 */
final class RefuseTrainingMode
{
    public function handle(Request $request, Closure $next): Response
    {
        $flag = $request->input('training', $request->query('training'));
        if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true') {
            return response()->json(['data' => null, 'errors' => [[
                'code' => 'training_refused',
                'message' => 'Training-mode work is never sent to the server.',
            ]]], 403);
        }

        return $next($request);
    }
}
