<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use App\Models\QrSession;
use App\Models\Table;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCompanyActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = null;
        if ($request->is('api/v1/public/qr/*')) {
            $uuid = $request->header('X-QR-Session');
            $session = is_string($uuid) && Str::isUuid($uuid)
                ? QrSession::query()->where('uuid', $uuid)->first() : null;
            if ($session === null && $request->is('api/v1/public/qr/bind')) {
                $session = QrSession::query()->where('token', (string) $request->input('token'))->first();
            }
            $companyId = $session?->company_id;
            if ($companyId === null) {
                $tableToken = $request->input('table_token', $request->query('t'));
                if (is_string($tableToken) && $tableToken !== '') {
                    $companyId = Table::query()->where('qr_token', trim($tableToken))->value('company_id');
                }
            }
        } else {
            $companyId = Auth::guard('pos_device')->user()?->company_id;
            if ($companyId === null && $request->is('api/v1/auth/device/*')) {
                $code = $request->input('code', $request->input('activation_token'));
                if (is_string($code)) {
                    $deviceId = DeviceActivationToken::query()->where('token_hash', hash('sha256', $code))->value('device_id');
                    $companyId = Device::query()->whereKey($deviceId)->value('company_id');
                }
            }
        }
        if ($companyId !== null && ! DB::table('pos_companies')->where('id', $companyId)
            ->where('status', 'active')->whereNull('deleted_at')->exists()) {
            return response()->json(['data' => null, 'errors' => [
                ['code' => 'company_suspended', 'message' => 'Account suspended.'],
            ]], 403);
        }

        return $next($request);
    }
}
