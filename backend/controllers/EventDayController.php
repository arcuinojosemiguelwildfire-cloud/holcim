<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\EventDayService;
use App\Utils\Validator;

final class EventDayController
{
    /** GET /event-days/current - any signed-in user */
    public static function current(Request $request): Response
    {
        return Response::success(EventDayService::current());
    }

    /** GET /events/{id}/days (admin) */
    public static function index(Request $request): Response
    {
        $eventId = Validator::id($request->param('id'), 'Event');

        return Response::success(['days' => EventDayService::listForEvent($eventId)]);
    }

    /** POST /events/{id}/days (admin) {event_date, label?} */
    public static function store(Request $request): Response
    {
        $eventId = Validator::id($request->param('id'), 'Event');

        return Response::created(['day' => EventDayService::create($request, $eventId, self::validate($request))], 'Event day added.');
    }

    /** PUT /event-days/{id} (admin) {event_date, label?} */
    public static function update(Request $request): Response
    {
        $dayId = Validator::id($request->param('id'), 'Event day');

        return Response::success(['day' => EventDayService::update($request, $dayId, self::validate($request))], message: 'Event day updated.');
    }

    /** PATCH /event-days/{id}/activate (admin) */
    public static function activate(Request $request): Response
    {
        $dayId = Validator::id($request->param('id'), 'Event day');

        return Response::success(['day' => EventDayService::activate($request, $dayId)], message: 'Current event day updated.');
    }

    /** @return array{event_date: string, label?: ?string} */
    private static function validate(Request $request): array
    {
        /** @var array{event_date: string, label?: ?string} */
        return Validator::make($request->body())
            ->date('event_date', required: true, label: 'Date')
            ->string('label', max: 100, label: 'Label')
            ->validate();
    }
}
