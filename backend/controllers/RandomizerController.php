<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\MinorRandomizerService;

final class RandomizerController
{
    /** GET /randomizers/minor - event, eligible count, recent winners */
    public static function minorSummary(Request $request): Response
    {
        return Response::success(MinorRandomizerService::summary());
    }

    /** POST /randomizers/minor/draw - server-side draw from the eligible pool */
    public static function minorDraw(Request $request): Response
    {
        return Response::success(MinorRandomizerService::draw($request));
    }
}
