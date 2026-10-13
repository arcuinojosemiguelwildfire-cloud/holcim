<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\PredrawnRevealService;
use App\Utils\Validator;

/**
 * Pre-Drawn Winner Reveal (active event day). Roles in routes.php:
 * view / roll names / reveal = admin + event operator; assignments and
 * voids = admin only. Separate from the Minor / Major Randomizer.
 */
final class PredrawnController
{
    /** GET /predrawn */
    public static function index(Request $request): Response
    {
        return Response::success(PredrawnRevealService::overview());
    }

    /** GET /predrawn/roll-names */
    public static function rollNames(Request $request): Response
    {
        return Response::success(PredrawnRevealService::rollNames());
    }

    /** POST /predrawn/assignments {letter, attendee_id, prize_label?} (admin) */
    public static function assign(Request $request): Response
    {
        return Response::created(PredrawnRevealService::assign($request, $request->body()), 'Pre-drawn winner assigned.');
    }

    /** PUT /predrawn/assignments/{id} {letter, attendee_id, prize_label?} (admin; not yet revealed) */
    public static function update(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Assignment');

        return Response::success(PredrawnRevealService::update($request, $id, $request->body()), message: 'Assignment updated.');
    }

    /** DELETE /predrawn/assignments/{id} (admin; not yet revealed) */
    public static function remove(Request $request): Response
    {
        PredrawnRevealService::remove($request, Validator::id($request->param('id'), 'Assignment'));

        return Response::success(null, message: 'Assignment removed.');
    }

    /** POST /predrawn/reveal {letter} (admin + event operator) */
    public static function reveal(Request $request): Response
    {
        return Response::success(PredrawnRevealService::reveal($request, $request->body()['letter'] ?? null), message: 'Pre-drawn winner revealed.');
    }

    /** POST /predrawn/assignments/{id}/void {reason?} (admin; revealed only) */
    public static function void(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Assignment');
        $data = Validator::make($request->body())->string('reason', max: 200, label: 'Reason')->validate();

        return Response::success(PredrawnRevealService::void($request, $id, $data['reason'] ?? null), message: 'Reveal voided.');
    }
}
