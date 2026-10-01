<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RegistrationService;

final class RegistrationController
{
    /** GET /registration/summary - day counters, personal/general scans, recent check-ins */
    public static function summary(Request $request): Response
    {
        return Response::success(RegistrationService::summary());
    }

    /** POST /registration/scan {token} - token or full QR URL */
    public static function scan(Request $request): Response
    {
        $value = $request->input('token');
        if (!is_string($value) || trim($value) === '') {
            throw HttpException::validation(['token' => ['Scan a QR code.']]);
        }

        return Response::success(RegistrationService::scan($request, $value));
    }

    /** GET /registration/scans?view=mine|all&result=all|successful|already_registered|invalid&page= */
    public static function scans(Request $request): Response
    {
        $view = $request->query('view', 'mine');
        $result = $request->query('result', 'all');

        return Response::success(RegistrationService::scans(
            is_string($view) ? $view : 'mine',
            is_string($result) ? $result : 'all',
            (int) $request->query('page', 1)
        ));
    }

    /** GET /registration/attendees?search= - read-only status lookup */
    public static function attendees(Request $request): Response
    {
        $search = $request->query('search', '');

        return Response::success(RegistrationService::searchAttendees(is_string($search) ? $search : ''));
    }
}
