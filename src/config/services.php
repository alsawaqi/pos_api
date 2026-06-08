<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // The charity Laravel API (shared charity_db, reachable over charity_net).
    // A POS card round-up is forwarded to its store_dhofar so a real
    // charity_transaction (+ shares) is created for a dual-registered device.
    // Unset (null) ⇒ forwarding is skipped (the pos_roundup_donations row still
    // records it). In the docker dev stack this is the charity nginx container.
    'charity' => [
        'url' => env('CHARITY_API_URL'),
        'timeout' => (int) env('CHARITY_API_TIMEOUT', 8),
    ],

];
