<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Utils\Csrf;

final class CsrfMiddleware
{
    /** Rejects state-changing requests without a valid X-CSRF-Token header. */
    public static function verify(Request $request): void
    {
        if (!Csrf::isValid($request->header(Csrf::HEADER))) {
            throw HttpException::csrf();
        }
    }
}
