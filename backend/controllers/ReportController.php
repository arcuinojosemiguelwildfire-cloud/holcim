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

    /** GET /reports/eligibility.csv?scope=day|all - Minor/Major eligibility from registration */
    public static function eligibility(Request $request): Response
    {
        return ReportService::eligibility(self::scope($request));
    }

    /** GET /reports/day-attendees.xlsx?day_id= - "Day N Attendees.xlsx" (a day of the active event; default current day) */
    public static function dayAttendees(Request $request): Response
    {
        $dayId = $request->query('day_id');

        return ReportService::dayAttendeesXlsx(is_string($dayId) && ctype_digit($dayId) ? (int) $dayId : null);
    }

    /** GET /reports/winners.xlsx?scope=all|day - Winners.xlsx (default all days) */
    public static function winners(Request $request): Response
    {
        $scope = $request->query('scope', 'all');

        return ReportService::winnersXlsx(is_string($scope) && $scope === 'day' ? 'day' : 'all');
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
