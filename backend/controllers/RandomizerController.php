<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
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

    /** GET /randomizers/{minor|major}/candidates?search= - attendees to add manually */
    public static function minorCandidates(Request $request): Response
    {
        return self::candidates($request, RandomizerDraw::TYPE_MINOR);
    }

    public static function majorCandidates(Request $request): Response
    {
        return self::candidates($request, RandomizerDraw::TYPE_MAJOR);
    }

    /** GET /randomizers/{minor|major}/participants?search=&page= - today's pool with source */
    public static function minorParticipants(Request $request): Response
    {
        return self::participants($request, RandomizerDraw::TYPE_MINOR);
    }

    public static function majorParticipants(Request $request): Response
    {
        return self::participants($request, RandomizerDraw::TYPE_MAJOR);
    }

    /** POST /randomizers/{minor|major}/participants {attendee_id, reason?} - manual add for today */
    public static function addMinorParticipant(Request $request): Response
    {
        return self::addParticipant($request, RandomizerDraw::TYPE_MINOR);
    }

    public static function addMajorParticipant(Request $request): Response
    {
        return self::addParticipant($request, RandomizerDraw::TYPE_MAJOR);
    }

    private static function candidates(Request $request, string $type): Response
    {
        $search = $request->query('search', '');

        return Response::success(RandomizerService::candidates($type, is_string($search) ? $search : ''));
    }

    private static function participants(Request $request, string $type): Response
    {
        $search = $request->query('search', '');

        return Response::success(RandomizerService::participants($type, is_string($search) ? $search : '', (int) $request->query('page', 1)));
    }

    private static function addParticipant(Request $request, string $type): Response
    {
        $attendeeId = $request->input('attendee_id');
        if (!is_int($attendeeId) && !(is_string($attendeeId) && ctype_digit($attendeeId))) {
            throw HttpException::validation(['attendee_id' => ['Select an attendee.']]);
        }
        $data = Validator::make($request->body())->string('reason', max: 200, label: 'Reason')->validate();
        $result = RandomizerService::addParticipant($request, $type, (int) $attendeeId, $data['reason'] ?? null);

        return Response::created($result, "{$result['attendee']['fullName']} added to today's " . ucfirst($type) . ' pool.');
    }
}
