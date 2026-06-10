<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | §11.5 device real-time push. Production runs the `reverb` driver (a
    | self-hosted WebSocket server started with `php artisan reverb:start`);
    | local dev defaults to `log`; the test suite pins `null` (phpunit.xml) so
    | events are asserted via Event::fake(), never sent over a socket.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle options for the server → Reverb HTTP publish call.
            ],
            // Phase C3 — what the DEVICE dials (often different from the
            // publish host above, which is the in-compose service DNS). A
            // null host means "use the API host you already talk to" — right
            // for dev where only the device knows the LAN IP; prod sets
            // REVERB_PUBLIC_HOST to the public wss hostname. Served to
            // devices in /device/config meta.websocket.
            'public' => [
                'host' => env('REVERB_PUBLIC_HOST'),
                'port' => env('REVERB_PUBLIC_PORT', env('REVERB_PORT', 8080)),
                'scheme' => env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'http')),
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => (int) env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
