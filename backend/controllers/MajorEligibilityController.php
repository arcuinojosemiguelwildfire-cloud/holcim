<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\MajorEligibility;
use App\Models\RandomizerDraw;
use App\Services\AttendeeService;
use App\Services\MajorEligibilityImportService;

final class MajorEligibilityController
{
    /** GET /major-eligibility?search=&department=&page=&per_page= (active event, active attendees) */
    public static function index(Request $request): Response
    {
        $event = AttendeeService::activeEventOrFail();
        $eventId = (int) $event['id'];
        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, AttendeeService::PAGE_SIZES, true) ? $perPage : 25;
        $page = max(1, (int) $request->query('page', 1));
        $search = $request->query('search');
        $department = $request->query('department');

        $result = MajorEligibility::search(
            $eventId,
            is_string($search) ? mb_substr(trim($search), 0, 100) : '',
            is_string($department) ? $department : null,
            $perPage,
            ($page - 1) * $perPage
        );

        return Response::success([
            'event' => ['id' => $eventId, 'name' => $event['name']],
            'eligibleCount' => RandomizerDraw::countEligible($eventId, RandomizerDraw::TYPE_MAJOR),
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'attendeeCode' => $row['attendee_code'],
                'fullName' => $row['full_name'],
                'department' => $row['department'],
                'email' => $row['email'],
                'importedAt' => $row['imported_at'],
            ], $result['items']),
            'departments' => $result['departments'],
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $result['total'],
                'totalPages' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
        ]);
    }

    /** GET /major-eligibility/imports - recent import batches (counts only) */
    public static function imports(Request $request): Response
    {
        $event = AttendeeService::activeEventOrFail();

        return Response::success(['imports' => array_map(static function (array $row): array {
            $details = json_decode((string) $row['error_summary'], true);
            $summary = is_array($details) && isset($details['summary']) ? $details['summary'] : [];

            return [
                'id' => (int) $row['id'],
                'filename' => $row['original_filename'],
                'importedAt' => $row['created_at'],
                'importedBy' => $row['imported_by_name'],
                'totalRows' => (int) $row['total_rows'],
                'newlyEligible' => (int) $row['successful_rows'],
                'matched' => (int) ($summary['matched'] ?? 0) + (int) ($summary['alreadyEligible'] ?? 0),
                'unmatched' => (int) ($summary['unmatched'] ?? 0),
                'ambiguous' => (int) ($summary['ambiguous'] ?? 0),
                'inactive' => (int) ($summary['inactive'] ?? 0),
                'invalid' => (int) ($summary['invalid'] ?? 0),
            ];
        }, MajorEligibility::importHistory((int) $event['id']))]);
    }

    /** POST /major-eligibility/import/parse (admin, multipart "file") */
    public static function parseImport(Request $request): Response
    {
        AttendeeService::activeEventOrFail();
        $file = $_FILES['file'] ?? null;

        return Response::success(MajorEligibilityImportService::parse(is_array($file) ? $file : null));
    }

    /** POST /major-eligibility/import/preview (admin) {headers, rows, mapping} */
    public static function previewImport(Request $request): Response
    {
        $event = AttendeeService::activeEventOrFail();

        return Response::success(MajorEligibilityImportService::analyse((int) $event['id'], $request->body()));
    }

    /** POST /major-eligibility/import (admin) {filename, headers, rows, mapping} */
    public static function import(Request $request): Response
    {
        $event = AttendeeService::activeEventOrFail();
        $result = MajorEligibilityImportService::commit($request, (int) $event['id'], $request->body());

        return Response::created($result, "{$result['newlyEligible']} attendee(s) are now Major Eligible.");
    }
}
