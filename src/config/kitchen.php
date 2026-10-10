<?php

$publicKeyPath = env('KITCHEN_GRANT_PUBLIC_KEY_PATH');
$keyId = env('KITCHEN_GRANT_KEY_ID');
$publicKeys = is_string($publicKeyPath) && is_readable($publicKeyPath) && is_string($keyId) && $keyId !== ''
    ? [$keyId => file_get_contents($publicKeyPath)]
    : [];

return [
    // Installed code alone must never opt a branch in.
    'enabled' => env('KITCHEN_V2_ENABLED', false),
    'ca_private_key_path' => env('KITCHEN_CA_PRIVATE_KEY_PATH'),
    'root_certificate_path' => env('KITCHEN_CA_CERTIFICATE_PATH'),
    'cloud_url' => env('APP_URL'),
    'issuer' => 'mithqal-kitchen', 'audience' => 'mithqal-kitchen-lan-v1',
    'key_id' => env('KITCHEN_GRANT_KEY_ID'),
    'private_key_path' => env('KITCHEN_GRANT_PRIVATE_KEY_PATH'),
    'public_keys' => $publicKeys,
    'grant_seconds' => 43200,
];
