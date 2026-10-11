<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Attendee;
use App\Services\AttendeeImportService;
use App\Services\AttendeeRegistrationService;
use App\Services\AttendeeService;
use App\Utils\Validator;

final class AttendeeController
{
    /** GET /attendees?search=&department=&status=active|archived|all&page=&per_page= */
    public static function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'active');
        $status = in_array($status, Attendee::STATUSES, true) ? $status : ($status === 'all' ? null : Attendee::STATUS_ACTIVE);
        $department = $request->query('department');

        return Response::success(AttendeeService::list(
            is_string($request->query('search')) ? $request->query('search') : '',
            is_string($department) ? $department : null,
            $status,
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 25)
        ));
    }

    /** GET /attendees/{id} */
    public static function show(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Attendee');

        return Response::success(['attendee' => AttendeeService::get($id)]);
    }

    /**
     * POST /attendees (admin, event operator) - manual Add Attendee.
     * {full_name, company?, department? (Cluster), email?, external_identifier? (Employee ID)}
     * The attendee code is generated server-side; any client-sent code is ignored.
     */
    public static function create(Request $request): Response
    {
        $data = Validator::make($request->body())
            ->string('full_name', required: true, max: 200, label: 'Full name')
            ->string('company', max: 200, label: 'Company')
            ->string('department', max: 150, label: 'Cluster')
            ->email('email')
            ->string('external_identifier', max: 190, label: 'Employee ID')
            ->validate();
        $result = AttendeeService::create($request, $data);

        return Response::created($result, "Attendee {$result['attendee']['attendeeCode']} added.");
    }

    /** PUT /attendees/{id} (admin). attendee_code is not editable. */
    public static function update(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Attendee');
        $data = Validator::make($request->body())
            ->string('full_name', required: true, max: 200, label: 'Full name')
            ->string('company', max: 200, label: 'Company')
            ->string('department', max: 150, label: 'Cluster')
            ->email('email')
            ->string('external_identifier', max: 190, label: 'Employee ID / External identifier')
            ->validate();

        return Response::success(['attendee' => AttendeeService::update($request, $id, $data)], message: 'Attendee updated.');
    }

    /** PATCH /attendees/{id}/status (admin) {status: active|archived} */
    public static function updateStatus(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Attendee');
        $data = Validator::make($request->body())->in('status', Attendee::STATUSES, required: true)->validate();

        return Response::success(['attendee' => AttendeeService::setStatus($request, $id, $data['status'])]);
    }

    /** GET /attendees/{id}/registrations - registration sessions, history and eligibility per day (Phase 10) */
    public static function registrations(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Attendee');

        return Response::success(AttendeeRegistrationService::overview($id));
    }

    /**
     * PUT /attendees/{id}/registrations (admin) - Manage / renew registration.
     * {sessions: [{session_id, registered: bool}], reason?}
     * Only WHICH sessions the attendee is registered for is accepted; any
     * eligibility value sent by the client is ignored (eligibility is derived
     * on the server from the stored registrations).
     */
    public static function updateRegistrations(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Attendee');
        $body = $request->body();
        $data = Validator::make($body)->string('reason', max: 200, label: 'Reason')->validate();
        $raw = $body['sessions'] ?? null;
        if (!is_array($raw) || $raw === [] || count($raw) > 50) {
            throw HttpException::validation(['sessions' => ['Choose the registration sessions to save.']]);
        }
        $changes = [];
        $seen = [];
        foreach ($raw as $item) {
            $sessionId = is_array($item) ? ($item['session_id'] ?? null) : null;
            $registered = is_array($item) && array_key_exists('registered', $item)
                ? filter_var($item['registered'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
            if (!is_int($sessionId) && !(is_string($sessionId) && ctype_digit($sessionId)) || $registered === null) {
                throw HttpException::validation(['sessions' => ['Each session needs a session_id and registered (true/false).']]);
            }
            $sessionId = (int) $sessionId;
            if (isset($seen[$sessionId])) {
                throw HttpException::validation(['sessions' => ['A session is listed more than once.']]);
            }
            $seen[$sessionId] = true;
            $changes[] = ['session_id' => $sessionId, 'registered' => $registered];
        }
        $result = AttendeeRegistrationService::update($request, $id, $changes, $data['reason'] ?? null);
        $count = count($result['applied']);

        return Response::success($result, message: $count === 0 ? 'No changes to save.' : "Saved {$count} registration change(s).");
    }

    /**
     * GET /attendees/import/template.xlsx (admin): the standard guest-sheet
     * template GUEST NO. | SURNAME | FIRST NAME | MIDDLE NAME |
     * INTERNAL/EXTERNAL | DESIGNATION / COMPANY (Company column in red).
     */
    public static function importTemplate(Request $request): Response
    {
        return Response::raw(200, AttendeeImportService::guestTemplate(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', [
            'Content-Disposition' => 'attachment; filename="Holcim Guest Import Template.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** POST /attendees/import/parse (admin, multipart "file") */
    public static function parseImport(Request $request): Response
    {
        AttendeeService::activeEventOrFail();
        $file = $_FILES['file'] ?? null;

        return Response::success(AttendeeImportService::parse(is_array($file) ? $file : null));
    }

    /** POST /attendees/import/preview (admin) {headers, rows, mapping} */
    public static function previewImport(Request $request): Response
    {
        $event = AttendeeService::activeEventOrFail();

        return Response::success(AttendeeImportService::analyse((int) $event['id'], $request->body()));
    }

    /** POST /attendees/import (admin) {filename, headers, rows, mapping} */
    public static function import(Request $request): Response
    {
        $event = AttendeeService::activeEventOrFail();
        $result = AttendeeImportService::commit($request, (int) $event['id'], $request->body());

        return Response::created($result, "Imported {$result['imported']} attendee(s).");
    }
}
