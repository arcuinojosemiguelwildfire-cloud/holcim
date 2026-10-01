<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;

/**
 * Emits CORS headers ONLY for origins explicitly listed in
 * CORS_ALLOWED_ORIGINS. With the default (empty list) the API is same-origin
 * only, which is what the Vite dev proxy and the production layout use.
 */
final class CorsMiddleware
{
    public static function apply(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $allowed = Config::get('app.cors_allowed_origins', []);

        if (!is_string($origin) || !in_array($origin, $allowed, true)) {
            return;
        }

        header("Access-Control-Allow-Origin: {$origin}");
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
        header('Access-Control-Max-Age: 600');
    }
}
