<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ReportService;

final class ReportController
{
    /** GET /reports/registration.csv */
    public static function registration(Request $request): Response
    {
        return ReportService::registration();
    }

    /** GET /reports/major-eligibility.csv */
    public static function majorEligibility(Request $request): Response
    {
        return ReportService::majorEligibility();
    }

    /** GET /reports/draws.csv */
    public static function draws(Request $request): Response
    {
        return ReportService::draws();
    }
}
