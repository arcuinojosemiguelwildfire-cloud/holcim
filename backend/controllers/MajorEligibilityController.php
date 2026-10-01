<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\EventDay;
use App\Models\MajorEligibility;
use App\Models\RandomizerDraw;
use App\Services\AttendeeService;
use App\Services\EventDayService;
use App\Services\MajorEligibilityImportService;

final class MajorEligibilityController
{
    /** GET /major-eligibility?search=&department=&page=&per_page= (active day, active attendees) */
    public static function index(Request $request): Response
    {
        [$event, $day] = EventDayService::activeContext();
        $eventId = (int) $event['id'];
        $dayId = (int) $day['id'];
        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, AttendeeService::PAGE_SIZES, true) ? $perPage : 25;
        $page = max(1, (int) $request->query('page', 1));
        $search = $request->query('search');
        $department = $request->query('department');

        $result = MajorEligibility::search(
            $dayId,
            is_string($search) ? mb_substr(trim($search), 0, 100) : '',
            is_string($department) ? $department : null,
            $perPage,
            ($page - 1) * $perPage
        );

        return Response::success([
            'event' => ['id' => $eventId, 'name' => $event['name']],
            'eventDay' => EventDay::toPublic($day),
            'eligibleCount' => RandomizerDraw::countEligible($dayId, RandomizerDraw::TYPE_MAJOR),
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'attendeeCode' => $row['attendee_code'],
                'fullName' => $row['full_name'],
                'department' => $row['department'],
                'email' => $row['email'],
                'importedAt' => $row['imported_at'],
                'source' => $row['source'],
                'addedBy' => $row['added_by_name'],
                'reason' => $row['reason'],
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

    /** GET /major-eligibility/imports - recent import batches of the active day (counts only) */
    public static function imports(Request $request): Response
    {
        [, $day] = EventDayService::activeContext();

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
        }, MajorEligibility::importHistory((int) $day['id'])), 'eventDay' => EventDay::toPublic($day)]);
    }

    /** POST /major-eligibility/import/parse (admin, multipart "file") */
    public static function parseImport(Request $request): Response
    {
        EventDayService::activeContext();
        $file = $_FILES['file'] ?? null;

        return Response::success(MajorEligibilityImportService::parse(is_array($file) ? $file : null));
    }

    /** POST /major-eligibility/import/preview (admin) {headers, rows, mapping} */
    public static function previewImport(Request $request): Response
    {
        [$event, $day] = EventDayService::activeContext();

        return Response::success(MajorEligibilityImportService::analyse((int) $event['id'], (int) $day['id'], $request->body()) + ['eventDay' => EventDay::toPublic($day)]);
    }

    /** POST /major-eligibility/import (admin) {filename, headers, rows, mapping} */
    public static function import(Request $request): Response
    {
        [$event, $day] = EventDayService::activeContext();
        $result = MajorEligibilityImportService::commit($request, (int) $event['id'], (int) $day['id'], $request->body());

        return Response::created($result + ['eventDay' => EventDay::toPublic($day)], "{$result['newlyEligible']} attendee(s) are now Major Eligible for Day {$day['day_number']}.");
    }
}
