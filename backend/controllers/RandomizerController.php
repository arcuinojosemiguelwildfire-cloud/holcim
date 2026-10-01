<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\RandomizerDraw;
use App\Services\RandomizerService;
use App\Utils\Validator;

final class RandomizerController
{
    /** GET /randomizers/minor - event, eligible count, recent Minor winners */
    public static function minorSummary(Request $request): Response
    {
        return Response::success(RandomizerService::summary(RandomizerDraw::TYPE_MINOR));
    }

    /** POST /randomizers/minor/draw - server-side draw from registered attendees */
    public static function minorDraw(Request $request): Response
    {
        return Response::success(RandomizerService::draw($request, RandomizerDraw::TYPE_MINOR));
    }

    /** GET /randomizers/major - event, eligible count, recent Major winners */
    public static function majorSummary(Request $request): Response
    {
        return Response::success(RandomizerService::summary(RandomizerDraw::TYPE_MAJOR));
    }

    /** POST /randomizers/major/draw - server-side draw from Major Eligible attendees */
    public static function majorDraw(Request $request): Response
    {
        return Response::success(RandomizerService::draw($request, RandomizerDraw::TYPE_MAJOR));
    }

    /** POST /randomizers/draws/{id}/void {reason?} - admin + event operator */
    public static function voidDraw(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Draw');
        $data = Validator::make($request->body())->string('reason', max: 200)->validate();

        return Response::success(RandomizerService::void($request, $id, $data['reason'] ?? null), message: 'Draw voided.');
    }
}
