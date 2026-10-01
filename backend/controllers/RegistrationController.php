<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RegistrationService;

final class RegistrationController
{
    /** GET /registration/summary - counters + recent check-ins for the active event */
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
}
