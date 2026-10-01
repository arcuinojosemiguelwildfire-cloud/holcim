<?php

declare(strict_types=1);

/**
 * API front controller. Every request under backend/ is rewritten here by
 * .htaccess (Apache) or passed in as the router script (php -S).
 */

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\CsrfMiddleware;

require __DIR__ . '/bootstrap.php';

$securityHeaders = [
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'Referrer-Policy' => 'no-referrer',
    'Cache-Control' => 'no-store',
];

foreach ($securityHeaders as $name => $value) {
    header("{$name}: {$value}");
}

try {
    CorsMiddleware::apply();
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $request = Request::fromGlobals();

    // CSRF is enforced globally for every state-changing request so no
    // future endpoint can forget it.
    if ($request->isStateChanging()) {
        CsrfMiddleware::verify($request);
    }

    $router = new Router();
    (require __DIR__ . '/api/routes.php')($router);

    $response = $router->dispatch($request);
} catch (HttpException $exception) {
    $response = Response::error(
        $exception->status(),
        $exception->errorCode(),
        $exception->getMessage(),
        $exception->details()
    );
} catch (\Throwable $exception) {
    error_log(sprintf(
        '[holcim-api] %s: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    $message = Config::get('app.debug')
        ? $exception->getMessage()
        : 'An unexpected server error occurred.';
    $response = Response::error(500, 'SERVER_ERROR', $message);
}

$response->send();
