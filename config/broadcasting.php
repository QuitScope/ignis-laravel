<?php

// Realtime is opt-in: `null` sends nothing and the pages keep polling exactly as before. `reverb` turns the
// pulses (App\Realtime\Realtime::pulse) and the `realtime` Inertia prop on. Configured by hand, no install:broadcasting
// (that would bake VITE_REVERB_* into the build; the client gets its values at runtime instead).
return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        // The app's own HTTP calls to the Reverb server: the INTERNAL address (Docker service `reverb`, or 127.0.0.1 on
        // a VPS), never the public URL. Browsers connect through the app's own origin (`/app`, see HandleInertiaRequests).
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8081),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                'timeout' => 1,
                'connect_timeout' => 1,
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
