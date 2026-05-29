<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8 hardening — make the device API JSON-only.
 *
 * A POS terminal (or a stray browser/curl) may hit the API without an
 * `Accept: application/json` header. Without this, Laravel would content-
 * negotiate an HTML error page for a 404/500 and, worse, redirect an
 * unauthenticated request to the non-existent `login` route. Pinning the
 * Accept header up front guarantees every failure path renders the JSON
 * envelope the device expects (and a clean 401 instead of a redirect).
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
