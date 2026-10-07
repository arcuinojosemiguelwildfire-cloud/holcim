<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\RegistrationSessionService;
use App\Utils\Validator;

/**
 * Registration sessions of an event day (Phase 10). Admin only (routes):
 * registration staff and scanner operators can see the current session but
 * never choose or change it.
 */
final class RegistrationSessionController
{
    /** GET /event-days/{id}/sessions */
    public static function index(Request $request): Response
    {
        $dayId = Validator::id($request->param('id'), 'Event day');

        return Response::success(['sessions' => RegistrationSessionService::listForDay($dayId)]);
    }

    /** POST /event-days/{id}/sessions {label, session_date?, start_time, description?, sort_order?, enabled?, counts_for_major} */
    public static function store(Request $request): Response
    {
        $dayId = Validator::id($request->param('id'), 'Event day');

        return Response::created(['session' => RegistrationSessionService::create($request, $dayId, self::validate($request))], 'Registration session added.');
    }

    /** PUT /registration-sessions/{id} */
    public static function update(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Registration session');

        return Response::success(['session' => RegistrationSessionService::update($request, $id, self::validate($request))], message: 'Registration session updated.');
    }

    /** PATCH /registration-sessions/{id}/activate - make it the current session of its day */
    public static function activate(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Registration session');

        return Response::success(['session' => RegistrationSessionService::activate($request, $id)], message: 'Current registration session updated.');
    }

    /** @return array<string, mixed> */
    private static function validate(Request $request): array
    {
        return Validator::make($request->body())
            ->string('label', required: true, max: 100, label: 'Session name')
            ->date('session_date', label: 'Registration date')
            ->time('start_time', required: true, label: 'Start time')
            ->string('description', max: 255, label: 'Description')
            ->integer('sort_order', min: 1, max: 999, label: 'Order')
            ->boolean('enabled', label: 'Enabled')
            ->boolean('counts_for_major', required: true, label: 'Counts for the Major Randomizer')
            ->validate();
    }
}
