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
            // Admission is scoped by the submitted credential, never an
            // unrelated existing browser session header.
            if ($request->is('api/v1/public/qr/table-menu', 'api/v1/public/qr/table-bind')) {
                $tableToken = $request->input('table_token', $request->query('t'));
                if (is_string($tableToken) && $tableToken !== '') {
                    $companyId = Table::query()->where('qr_token', trim($tableToken))->value('company_id');
                }
            } elseif ($request->is('api/v1/public/qr/bind')) {
                $companyId = QrSession::query()->where('token', (string) $request->input('token'))->value('company_id');
            } else {
                $uuid = $request->header('X-QR-Session');
                $companyId = is_string($uuid) && Str::isUuid($uuid)
                    ? QrSession::query()->where('uuid', $uuid)->value('company_id') : null;
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
            ->whereNotIn('status', ['suspended', 'inactive'])->whereNull('deleted_at')->exists()) {
            // Old APKs permanently park deterministic 4xx after five tries.
            // A 503 preserves their financial queue; new APKs still show the
            // suspended gate from the code. Public browsers retain 403.
            $public = $request->is('api/v1/public/qr/*');

            return response()->json(['data' => null, 'errors' => [
                ['code' => 'company_suspended', 'message' => 'Account suspended.'],
            ]], $public ? 403 : 503, $public ? [] : ['Retry-After' => '60']);
        }

        return $next($request);
    }
}
