<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Event;
use App\Services\EventService;
use App\Utils\Validator;

final class EventController
{
    /** GET /events */
    public static function index(Request $request): Response
    {
        return Response::success(['events' => EventService::list()]);
    }

    /** GET /events/{id} */
    public static function show(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Event');

        return Response::success(['event' => EventService::get($id)]);
    }

    /** POST /events (admin) */
    public static function store(Request $request): Response
    {
        $data = self::validateEvent($request);
        $event = EventService::create($request, $data);

        return Response::created(['event' => $event], 'Event created.');
    }

    /** PUT /events/{id} (admin) - full update of editable fields */
    public static function update(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Event');
        $data = self::validateEvent($request);
        $data['description'] ??= null;

        return Response::success(['event' => EventService::update($request, $id, $data)], message: 'Event updated.');
    }

    /** PATCH /events/{id}/status (admin) */
    public static function updateStatus(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Event');
        $data = Validator::make($request->body())
            ->in('status', Event::STATUSES, required: true)
            ->validate();

        return Response::success(['event' => EventService::update($request, $id, $data)], message: 'Event status updated.');
    }

    /** @return array<string, mixed> */
    private static function validateEvent(Request $request): array
    {
        return Validator::make($request->body())
            ->string('name', required: true, max: 200, label: 'Event name')
            ->string('description', max: 5000)
            ->date('event_date', required: true, label: 'Event date')
            ->in('status', Event::STATUSES, required: true)
            ->validate();
    }
}
