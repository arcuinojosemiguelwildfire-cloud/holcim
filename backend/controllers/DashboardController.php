<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\DashboardService;

final class DashboardController
{
    /** GET /dashboard/summary */
    public static function summary(Request $request): Response
    {
        return Response::success(DashboardService::summary());
    }
}
