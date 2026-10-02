<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Attendee;
use App\Services\AttendeeImportService;
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
