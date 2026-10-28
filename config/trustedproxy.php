<?php

// Read by the framework's global TrustProxies middleware. Unset = trust nothing.
// Comma-separated proxy IPs, or '*' behind a reverse proxy you control.
return ['proxies' => env('TRUSTED_PROXIES')];
