<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'Holcim Event System'),
    // local | production
    'env' => Env::get('APP_ENV', 'production'),
    // Never enable in production: exposes exception messages in API errors.
    'debug' => Env::bool('APP_DEBUG', false),
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Manila'),
    // Public base URL of the app (no trailing slash), e.g. https://events.example.com.
    // Optional. NOT used for attendee QR codes: since Phase 9.5 a QR contains
    // only the opaque token, so printed labels never depend on this value.
    'url' => Env::get('APP_URL', ''),
    // Comma-separated list of origins allowed to call the API with cookies.
    // Leave empty when the frontend is served from the same origin (default)
    // or through the Vite dev proxy.
    'cors_allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', Env::get('CORS_ALLOWED_ORIGINS', '') ?? '')
    ))),
];
