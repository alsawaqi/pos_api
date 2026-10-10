<?php

declare(strict_types=1);

namespace App\Kitchen;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use Throwable;

final class Grants
{
    public function issue(array $identity, array $scopes, ?int $staffId): array
    {
        $path = config('kitchen.private_key_path');
        $kid = config('kitchen.key_id');
        KitchenFault::require(is_string($path) && is_readable($path) && is_string($kid) && $kid !== '', 'kitchen_issuer_unconfigured', 503);
        $now = now()->timestamp;
        $claims = [...$identity, 'iss' => config('kitchen.issuer'), 'aud' => config('kitchen.audience'), 'jti' => (string) Str::uuid(), 'iat' => $now, 'nbf' => $now, 'exp' => $now + min(43200, (int) config('kitchen.grant_seconds')), 'staff_id' => $staffId, 'scopes' => $scopes];

        return ['grant' => JWT::encode($claims, file_get_contents($path), 'ES256', $kid), 'expires_at' => $claims['exp'], 'refresh_after_seconds' => 300];
    }

    public function verify(string $jwt, array $identity, string $scope): array
    {
        KitchenFault::require(strlen($jwt) > 20 && strlen($jwt) <= 16384, 'grant_invalid', 403);
        try {
            $keys = [];
            foreach (config('kitchen.public_keys', []) as $kid => $pem) {
                $keys[$kid] = new Key($pem, 'ES256');
            }
            KitchenFault::require($keys !== [], 'kitchen_issuer_unconfigured', 503);
            $claims = Wire::read(Wire::json(JWT::decode($jwt, $keys)));
            KitchenFault::require(($claims['iss'] ?? null) === config('kitchen.issuer') && ($claims['aud'] ?? null) === config('kitchen.audience'), 'grant_invalid', 403);
            foreach (['iat', 'nbf', 'exp'] as $k) {
                KitchenFault::require(is_int($claims[$k] ?? null), 'grant_invalid', 403);
            }
            KitchenFault::require($claims['exp'] > $claims['iat'] && $claims['exp'] - $claims['iat'] <= 43200 && $claims['nbf'] === $claims['iat'] && $claims['exp'] > now()->timestamp && $claims['iat'] <= now()->timestamp, 'grant_expired', 403);
            foreach ($identity as $key => $value) {
                KitchenFault::require(($claims[$key] ?? null) === $value, 'grant_identity_mismatch', 403);
            }
            KitchenFault::require(is_array($claims['scopes'] ?? null) && in_array($scope, $claims['scopes'], true), 'grant_scope_forbidden', 403);

            return $claims;
        } catch (KitchenFault $e) {
            throw $e;
        } catch (Throwable) {
            throw new KitchenFault('grant_invalid', 403);
        }
    }
}
