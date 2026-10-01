<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Response;
use App\Models\EventDay;
use App\Models\RandomizerDraw;

/**
 * CSV exports for the ACTIVE event (Phase 7), per event day (Phase 8).
 * The event and day are always resolved server-side; no event or day ID is
 * accepted from the browser. Scope:
 *   day = the current (active) Event Day only
 *   all = every day of the active event, one Event Day column per row
 */
final class ReportService
{
    public const SCOPES = ['day', 'all'];

    public static function registration(string $scope): Response
    {
        [$event, $day] = self::context($scope);
        [$dayJoin, $params] = self::dayJoin($event, $day);
        $statement = Database::connection()->prepare(
            "SELECT d.day_number, d.event_date AS day_date,
                    a.attendee_code, a.full_name, a.department, a.email, a.status,
                    r.scanned_at, u.name AS scanned_by
             FROM attendees a
             {$dayJoin}
             LEFT JOIN registration_scans r ON r.attendee_id = a.id AND r.event_day_id = d.id
             LEFT JOIN users u ON u.id = r.scanner_user_id
             WHERE a.event_id = :event_id
             ORDER BY d.day_number, a.attendee_code"
        );
        $statement->execute($params);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                self::dayCell($row), $row['attendee_code'], $row['full_name'], $row['department'], $row['email'],
                $row['scanned_at'] !== null ? 'Registered' : 'Not registered',
                $row['scanned_at'], $row['scanned_by'], ucfirst((string) $row['status']),
            ];
        }

        return self::csv(
            $event,
            $day,
            'registration',
            ['Event Day', 'Attendee Code', 'Full Name', 'Department', 'Email', 'Registration Status', 'Registered At', 'Registered By', 'Attendee Status'],
            $rows
        );
    }

    public static function majorEligibility(string $scope): Response
    {
        [$event, $day] = self::context($scope);
        [$dayJoin, $params] = self::dayJoin($event, $day);
        $statement = Database::connection()->prepare(
            "SELECT d.day_number, d.event_date AS day_date,
                    a.attendee_code, a.full_name, a.department, a.email, a.status,
                    m.id AS eligibility_id, m.source, m.imported_at, u.name AS added_by
             FROM attendees a
             {$dayJoin}
             LEFT JOIN major_eligibility m ON m.attendee_id = a.id AND m.event_day_id = d.id
             LEFT JOIN users u ON u.id = m.added_by
             WHERE a.event_id = :event_id
             ORDER BY d.day_number, (m.id IS NULL), a.attendee_code"
        );
        $statement->execute($params);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            // Eligible for the draw = record for that day AND attendee active (same rule as the randomizer).
            $hasRecord = $row['eligibility_id'] !== null;
            $rows[] = [
                self::dayCell($row), $row['attendee_code'], $row['full_name'], $row['department'], $row['email'],
                $hasRecord && $row['status'] === 'active' ? 'Yes' : 'No',
                $hasRecord ? ucfirst((string) $row['source']) : '',
                $row['imported_at'], $row['added_by'], ucfirst((string) $row['status']),
            ];
        }

        return self::csv(
            $event,
            $day,
            'major-eligibility',
            ['Event Day', 'Attendee Code', 'Full Name', 'Department', 'Email', 'Major Eligible', 'Eligibility Source', 'Imported/Added At', 'Added/Imported By', 'Attendee Status'],
            $rows
        );
    }

    public static function draws(string $scope): Response
    {
        [$event, $day] = self::context($scope);
        $rows = [];
        foreach (RandomizerDraw::allForEvent((int) $event['id'], $day !== null ? (int) $day['id'] : null) as $row) {
            $rows[] = [
                self::dayCell($row), ucfirst((string) $row['randomizer_type']), $row['attendee_code'], $row['full_name'], $row['department'],
                $row['selected_at'], $row['drawn_by_name'], $row['voided_at'] !== null ? 'VOID' : 'Valid',
                $row['voided_at'], $row['voided_by_name'], $row['void_reason'],
            ];
        }

        return self::csv(
            $event,
            $day,
            'draw-winners',
            ['Event Day', 'Draw Type', 'Attendee Code', 'Full Name', 'Department', 'Drawn At', 'Drawn By', 'Status', 'Voided At', 'Voided By', 'Void Reason'],
            $rows
        );
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>|null} [event, day or null for all days] */
    private static function context(string $scope): array
    {
        if ($scope === 'all') {
            return [AttendeeService::activeEventOrFail(), null];
        }

        return EventDayService::activeContext();
    }

    /**
     * Join producing alias `d` = the selected day, or every day of the event.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private static function dayJoin(array $event, ?array $day): array
    {
        $params = ['event_id' => (int) $event['id']];
        if ($day !== null) {
            $params['day_id'] = (int) $day['id'];

            return ['JOIN event_days d ON d.id = :day_id', $params];
        }
        $params['day_event_id'] = (int) $event['id'];

        return ['JOIN event_days d ON d.event_id = :day_event_id', $params];
    }

    /** @param array<string, mixed> $row */
    private static function dayCell(array $row): string
    {
        return EventDay::shortName(['day_number' => $row['day_number'], 'event_date' => $row['day_date']]);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed>|null $day null = all days
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     */
    private static function csv(array $event, ?array $day, string $name, array $headers, array $rows): Response
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names correctly
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'safeCell'], $row), ',', '"', '');
        }
        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $event['name'])), '-') ?: 'event';
        $scope = $day !== null ? 'day-' . $day['day_number'] : 'all-days';
        $filename = "{$slug}-{$name}-{$scope}-" . date('Y-m-d-Hi') . '.csv';

        return Response::raw(200, $body, 'text/csv; charset=utf-8', [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** Neutralises spreadsheet formula injection (=, +, -, @ at the start). */
    private static function safeCell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'" . $text : $text;
    }
}
