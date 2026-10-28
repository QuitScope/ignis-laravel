<?php

// The Reverb WebSocket server (`php artisan reverb:start`), only used with BROADCAST_CONNECTION=reverb. One process, one
// app.
// ponytail: one process without ext-uv (stream_select, ~1024 descriptors), hence max_connections 1000. Beyond that:
// ext-uv in the image, or a second node with `scaling` over Redis (no Redis in the stack today).
return [

    'default' => 'reverb',

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8081),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => 10_000,
            'scaling' => [
                'enabled' => false,
                'channel' => 'reverb',
                'server' => [],
            ],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],

    ],

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST', '127.0.0.1'),
                    'port' => env('REVERB_PORT', 8081),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                    'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
                ],
                // NUI and DUI load the ignis page itself, so the handshake's Origin is always the APP_URL host.
                'allowed_origins' => [(string) (parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost')],
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_connections' => 1000,
                'max_message_size' => 10_000,
                // The client only listens to `.pulse`; no whisper/client-* events at all (anything but all|members).
                'accept_client_events_from' => 'none',
                'rate_limiting' => [
                    'enabled' => false,
                ],
            ],
        ],

    ],

];
