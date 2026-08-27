<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$probe = static function (Request $request) {
    // Touch the real web session so the response exercises Laravel's session
    // cookie settings rather than manufacturing a Set-Cookie header.
    $request->session()->put('true_client_ip_probe', bin2hex(random_bytes(8)));

    return response()->json([
        'request_ip' => $request->ip(),
        'request_ips' => $request->ips(),
        'request_secure' => $request->secure(),
        'request_scheme' => $request->getScheme(),
        'server_remote_addr' => $request->server('REMOTE_ADDR'),
        'php_server_remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'received_x_forwarded_for' => $request->header('X-Forwarded-For'),
        'received_x_real_ip' => $request->header('X-Real-IP'),
        'received_cf_connecting_ip' => $request->header('CF-Connecting-IP'),
    ]);
};

Route::get('/_ops/true-client-ip', $probe);

// Exercise the application's real named limiter and key normalisation. Varying
// kiosk_id in the runner keeps its independent kiosk bucket out of the result.
Route::get('/_ops/true-client-ip/limited', $probe)
    ->middleware('throttle:device-pair');
