<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QrBrowserRecoveryScopeTest extends TestCase
{
    use RefreshDatabase;

    private function pending(int $company, int $branch): QrSession
    {
        $device = Device::factory()->paired('mdev_'.Str::random(12))->create([
            'company_id' => $company, 'branch_id' => $branch, 'device_type' => 'payment_station',
        ]);

        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => $company, 'branch_id' => $branch,
            'device_id' => $device->id, 'token' => Str::random(64),
            'token_expires_at' => now()->addMinute(), 'status' => QrSession::STATUS_PENDING,
            'expires_at' => now()->addHours(2),
        ]);
    }

    public function test_scope_is_stable_across_stations_but_separates_merchants_and_branches(): void
    {
        $scopes = [];
        foreach ([[100, 10], [100, 10], [100, 11], [101, 10]] as [$company, $branch]) {
            $session = $this->pending($company, $branch);
            $response = $this->postJson('/api/v1/public/qr/bind', [
                'token' => $session->token, 'client_secret' => str_repeat('a', 64),
            ])->assertOk();
            $scope = $response->json('meta.recovery_scope');
            $this->assertIsString($scope);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $scope);
            $scopes[] = $scope;
        }
        $this->assertSame($scopes[0], $scopes[1]);
        $this->assertNotSame($scopes[0], $scopes[2]);
        $this->assertNotSame($scopes[0], $scopes[3]);
    }

    public function test_scope_does_not_authorize_another_browser_to_bind_or_read(): void
    {
        $session = $this->pending(100, 10);
        $response = $this->postJson('/api/v1/public/qr/bind', [
            'token' => $session->token, 'client_secret' => str_repeat('a', 64),
        ])->assertOk();
        $scope = $response->json('meta.recovery_scope');
        $this->assertIsString($scope);
        $this->postJson('/api/v1/public/qr/bind', [
            'token' => $session->token, 'client_secret' => $scope,
        ])->assertNotFound();
        $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => $scope])
            ->getJson('/api/v1/public/qr/status')->assertNotFound();
    }
}
