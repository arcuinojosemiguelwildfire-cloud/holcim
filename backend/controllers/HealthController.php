<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class HealthController
{
    /** GET /health - public liveness + database connectivity check. */
    public static function show(Request $request): Response
    {
        $databaseOk = true;
        try {
            Database::connection()->query('SELECT 1')->fetchColumn();
        } catch (\Throwable $exception) {
            $databaseOk = false;
            error_log('[holcim-api] Health check database failure: ' . $exception->getMessage());
        }

        $data = [
            'status' => $databaseOk ? 'ok' : 'degraded',
            'app' => Config::get('app.name'),
            'environment' => Config::get('app.env'),
            'database' => $databaseOk ? 'connected' : 'unavailable',
            'time' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        return Response::success($data, $databaseOk ? 200 : 503, 'Holcim Event System API is running.');
    }
}
