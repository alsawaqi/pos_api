<?php

declare(strict_types=1);

namespace App\Kitchen;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Enrolled device proof-of-key; never enrolls or changes an assignment. */
final class Certificates
{
    public function issue(Access $access, array $input): array
    {
        Validator::make($input, ['csr' => 'required|string|max:16384', 'certificate' => 'required|string|max:16384', 'renewal_id' => 'sometimes|uuid'])->validate();
        $csrKey = @openssl_csr_get_public_key($input['csr']);
        $oldKey = @openssl_pkey_get_public($input['certificate']);
        $details = $csrKey ? openssl_pkey_get_details($csrKey) : false;
        $old = $oldKey ? openssl_pkey_get_details($oldKey) : false;
        KitchenFault::require($details && $old && ($details['ec']['curve_name'] ?? null) === 'prime256v1' && hash_equals($old['key'], $details['key']), 'certificate_key_mismatch', 422);
        $oldThumb = @openssl_x509_fingerprint($input['certificate'], 'sha256');
        KitchenFault::require(is_string($oldThumb), 'certificate_invalid', 422);
        $rootPath = config('kitchen.root_certificate_path');
        $keyPath = config('kitchen.ca_private_key_path');
        KitchenFault::require(is_string($rootPath) && is_readable($rootPath) && is_string($keyPath) && is_readable($keyPath), 'kitchen_ca_unconfigured', 503);
        $root = file_get_contents($rootPath);
        $key = openssl_pkey_get_private(file_get_contents($keyPath));
        KitchenFault::require($key && openssl_x509_check_private_key($root, $key), 'kitchen_ca_unconfigured', 503);

        return DB::transaction(function () use ($access, $input, $details, $oldThumb, $root, $key): array {
            $binding = DB::table('pos_kv2_devices')->where('id', $access->binding->id)->lockForUpdate()->first();
            KitchenFault::require($binding && $binding->enabled && $binding->assignment === $access->binding->assignment, 'device_not_enrolled', 403);
            $directory = storage_path('app/private/kitchen-certificates');
            if (! is_dir($directory)) {
                @mkdir($directory, 0700, true);
            }
            KitchenFault::require(is_dir($directory), 'certificate_persistence_failed', 503);
            $id = hash('sha256', $binding->device_id.'|'.$binding->assignment.'|'.$details['key'].'|'.openssl_x509_fingerprint($root, 'sha256').(isset($input['renewal_id']) ? '|renew:'.$input['renewal_id'] : ''));
            $path = $directory.'/'.$id.'.json';
            $requestHash = hash('sha256', $details['key'].'|'.$oldThumb);
            $record = is_file($path) ? json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR) : null;
            KitchenFault::require($record === null || hash_equals($record['request_hash'], $requestHash), 'certificate_request_conflict', 409);
            $cached = $record['certificate'] ?? null;
            $cachedThumb = $cached ? openssl_x509_fingerprint($cached, 'sha256') : null;
            KitchenFault::require(hash_equals($binding->certificate_thumbprint, $oldThumb) || ($cachedThumb && hash_equals($binding->certificate_thumbprint, $cachedThumb)), 'certificate_not_enrolled', 403);
            // A persisted issuance is returned unchanged after a lost response;
            // the native private key never leaves the requesting Android device.
            if (! $cached) {
                // A new renewal must prove the currently enrolled certificate.
                // The request UUID selects an immutable result for ACK-loss retry.
                KitchenFault::require(hash_equals($binding->certificate_thumbprint, $oldThumb), 'certificate_not_enrolled', 403);
                $signed = @openssl_csr_sign($input['csr'], $root, $key, 7, ['digest_alg' => 'sha256', 'config' => config_path('kitchen-certificate.cnf'), 'x509_extensions' => 'v3_kitchen'], random_int(1, PHP_INT_MAX));
                KitchenFault::require($signed !== false, 'csr_signature_invalid', 422);
                openssl_x509_export($signed, $cached);
                $temporary = tempnam($directory, 'pending-');
                chmod($temporary, 0600);
                try {
                    $saved = json_encode(['request_hash' => $requestHash, 'certificate' => $cached], JSON_THROW_ON_ERROR);
                    $handle = fopen($temporary, 'wb');
                    try {
                        KitchenFault::require($handle && fwrite($handle, $saved) === strlen($saved) && fflush($handle) && fsync($handle), 'certificate_persistence_failed', 503);
                    } finally {
                        if ($handle) {
                            fclose($handle);
                        }
                    }
                    KitchenFault::require(rename($temporary, $path), 'certificate_persistence_failed', 503);
                    $directoryHandle = fopen($directory, 'r');
                    try {
                        KitchenFault::require($directoryHandle && fsync($directoryHandle), 'certificate_persistence_failed', 503);
                    } finally {
                        if ($directoryHandle) {
                            fclose($directoryHandle);
                        }
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
                $cachedThumb = openssl_x509_fingerprint($cached, 'sha256');
            }
            KitchenFault::require((openssl_x509_parse($cached)['validTo_time_t'] ?? 0) > now()->timestamp, 'certificate_renewal_requires_review', 409);
            DB::table('pos_kv2_devices')->where('id', $binding->id)->update(['certificate_thumbprint' => $cachedThumb, 'updated_at' => now()]);

            return ['certificate' => $cached, 'root_certificate' => $root, 'thumbprint' => $cachedThumb];
        }, 5);
    }
}
