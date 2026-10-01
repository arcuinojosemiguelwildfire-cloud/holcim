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
    // Printed attendee QR codes contain "{APP_URL}/q/{token}". If empty, the
    // QR contains only the token. Set it BEFORE printing QR codes.
    'url' => Env::get('APP_URL', ''),
    // External form for the Major draw (e.g. a Google Form). The LED-screen QR
    // points to {APP_URL}/major-form, which redirects here, so this can change
    // without regenerating the QR. Leave empty until the client provides it.
    'major_form_url' => Env::get('MAJOR_FORM_URL', ''),
    // Comma-separated list of origins allowed to call the API with cookies.
    // Leave empty when the frontend is served from the same origin (default)
    // or through the Vite dev proxy.
    'cors_allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', Env::get('CORS_ALLOWED_ORIGINS', '') ?? '')
    ))),
];
