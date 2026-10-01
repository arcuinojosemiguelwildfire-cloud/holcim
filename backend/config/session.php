<?php

declare(strict_types=1);

use App\Core\Env;

$sameSite = ucfirst(strtolower(Env::get('SESSION_SAMESITE', 'Lax') ?? 'Lax'));

return [
    'name' => Env::get('SESSION_NAME', 'holcim_session'),
    // Idle timeout in seconds (default 8 hours = a full event day).
    'lifetime' => Env::int('SESSION_LIFETIME', 28800),
    'path' => Env::get('SESSION_PATH', '/'),
    'domain' => Env::get('SESSION_DOMAIN', ''),
    // MUST be true in production (HTTPS). False only for http://localhost.
    'secure' => Env::bool('SESSION_SECURE_COOKIE', true),
    'samesite' => in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax',
];
