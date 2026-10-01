<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ReportService;

final class ReportController
{
    /** GET /reports/registration.csv?scope=day|all */
    public static function registration(Request $request): Response
    {
        return ReportService::registration(self::scope($request));
    }

    /** GET /reports/major-eligibility.csv?scope=day|all */
    public static function majorEligibility(Request $request): Response
    {
        return ReportService::majorEligibility(self::scope($request));
    }

    /** GET /reports/draws.csv?scope=day|all */
    public static function draws(Request $request): Response
    {
        return ReportService::draws(self::scope($request));
    }

    /** ?scope=day (current Event Day, default) | all (every day of the active event) */
    private static function scope(Request $request): string
    {
        $scope = $request->query('scope', 'day');

        return is_string($scope) && in_array($scope, ReportService::SCOPES, true) ? $scope : 'day';
    }
}
