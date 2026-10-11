<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\EventDay;
use App\Models\RandomizerDraw;
use App\Utils\XlsxWriter;

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
            "SELECT d.id AS day_id, d.day_number, d.event_date AS day_date,
                    a.id, a.attendee_code, a.full_name, a.company, a.department, a.email, a.status
             FROM attendees a
             {$dayJoin}
             WHERE a.event_id = :event_id
             ORDER BY d.day_number, a.attendee_code"
        );
        $statement->execute($params);
        $registrations = self::sessionRegistrations((int) $event['id'], $day !== null ? (int) $day['id'] : null);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $list = $registrations[(int) $row['id']][(int) $row['day_id']] ?? [];
            $first = $list[0] ?? null;
            $rows[] = [
                self::dayCell($row), $row['attendee_code'], $row['full_name'], $row['company'], $row['department'], $row['email'],
                $first !== null ? 'Registered' : 'Not registered',
                $first['registered_at'] ?? null, $first['registered_by'] ?? null, ucfirst((string) $row['status']),
                self::sessionList($list),
            ];
        }

        return self::csv(
            $event,
            $day,
            'registration',
            ['Event Day', 'Attendee Code', 'Full Name', 'Company', 'Cluster', 'Email', 'Registration Status', 'Registered At', 'Registered By', 'Attendee Status', 'Registration Sessions'],
            $rows
        );
    }

    /**
     * Raffle eligibility per attendee per day (Phase 10): a registration in
     * ANY session of the day makes an attendee eligible for Minor; only a
     * registration in a session that counts for Major makes them eligible for
     * Major. A manual addition counts for its own randomizer only. "Won" =
     * valid (not void) draw that day. Same rules as the draw pools.
     */
    public static function eligibility(string $scope): Response
    {
        [$event, $day] = self::context($scope);
        [$dayJoin, $params] = self::dayJoin($event, $day);
        $statement = Database::connection()->prepare(
            "SELECT d.id AS day_id, d.day_number, d.event_date AS day_date,
                    a.id, a.attendee_code, a.full_name, a.company, a.department, a.email, a.status,
                    (mm.id IS NOT NULL) AS minor_manual,
                    (mj.id IS NOT NULL) AS major_manual,
                    EXISTS (SELECT 1 FROM randomizer_draws w WHERE w.event_day_id = d.id AND w.attendee_id = a.id
                            AND w.randomizer_type = 'minor' AND w.voided_at IS NULL) AS won_minor,
                    EXISTS (SELECT 1 FROM randomizer_draws w2 WHERE w2.event_day_id = d.id AND w2.attendee_id = a.id
                            AND w2.randomizer_type = 'major' AND w2.voided_at IS NULL) AS won_major
             FROM attendees a
             {$dayJoin}
             LEFT JOIN minor_manual_entries mm ON mm.attendee_id = a.id AND mm.event_day_id = d.id
             LEFT JOIN major_eligibility mj ON mj.attendee_id = a.id AND mj.event_day_id = d.id AND mj.source = 'manual'
             WHERE a.event_id = :event_id
             ORDER BY d.day_number, a.attendee_code"
        );
        $statement->execute($params);
        $registrations = self::sessionRegistrations((int) $event['id'], $day !== null ? (int) $day['id'] : null);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $list = $registrations[(int) $row['id']][(int) $row['day_id']] ?? [];
            $registered = $list !== [];
            $majorRegistered = array_filter($list, static fn (array $r): bool => $r['counts_for_major']) !== [];
            $active = $row['status'] === 'active';
            $minor = $active && ($registered || $row['minor_manual']);
            $major = $active && ($majorRegistered || $row['major_manual']);
            $rows[] = ['sort' => [(int) $row['day_number'], $registered ? 0 : 1, (string) $row['attendee_code']], 'cells' => [
                self::dayCell($row), $row['attendee_code'], $row['full_name'], $row['company'], $row['department'], $row['email'],
                $registered ? 'Yes' : 'No',
                $minor ? 'Yes' : 'No', $minor ? ($registered ? 'Registration' : 'Manual') : '', $row['won_minor'] ? 'Yes' : 'No',
                $major ? 'Yes' : 'No', $major ? ($majorRegistered ? 'Registration' : 'Manual') : '', $row['won_major'] ? 'Yes' : 'No',
                ucfirst((string) $row['status']),
                self::sessionList($list),
            ]];
        }
        // Registered first within each day (as before), then by attendee code.
        usort($rows, static fn (array $x, array $y): int => $x['sort'] <=> $y['sort']);

        return self::csv(
            $event,
            $day,
            'raffle-eligibility',
            ['Event Day', 'Attendee Code', 'Full Name', 'Company', 'Cluster', 'Email', 'Registered',
                'Minor Eligible', 'Minor Source', 'Won Minor', 'Major Eligible', 'Major Source', 'Won Major', 'Attendee Status', 'Registration Sessions'],
            array_map(static fn (array $r): array => $r['cells'], $rows)
        );
    }

    public static function draws(string $scope): Response
    {
        [$event, $day] = self::context($scope);
        $rows = [];
        foreach (RandomizerDraw::allForEvent((int) $event['id'], $day !== null ? (int) $day['id'] : null) as $row) {
            $rows[] = [
                self::dayCell($row), ucfirst((string) $row['randomizer_type']), $row['attendee_code'], $row['full_name'], $row['company'], $row['department'],
                $row['selected_at'], $row['drawn_by_name'], $row['voided_at'] !== null ? 'VOID' : 'Valid',
                $row['voided_at'], $row['voided_by_name'], $row['void_reason'],
                $row['reset_at'], $row['reset_at'] !== null ? $row['reset_by_name'] : null,
            ];
        }

        return self::csv(
            $event,
            $day,
            'draw-winners',
            ['Event Day', 'Draw Type', 'Attendee Code', 'Full Name', 'Company', 'Cluster', 'Drawn At', 'Drawn By', 'Status', 'Voided At', 'Voided By', 'Void Reason', 'Exclusion Reset At', 'Exclusion Reset By'],
            $rows
        );
    }

    /**
     * "Day N Attendees.xlsx" (Phase 9.2): every active attendee of the active
     * event with their registration status for that day. The same attendee
     * list is used for every day; each file is one day. $dayId must be a day
     * of the active event (defaults to the current day).
     */
    public static function dayAttendeesXlsx(?int $dayId): Response
    {
        $event = AttendeeService::activeEventOrFail();
        $day = $dayId !== null ? EventDay::find($dayId) : EventDay::findActiveForEvent((int) $event['id']);
        if ($day === null || (int) $day['event_id'] !== (int) $event['id']) {
            throw HttpException::notFound('Event day not found in the active event.');
        }
        $statement = Database::connection()->prepare(
            "SELECT a.id, a.attendee_code, a.full_name, a.company, a.department, a.external_identifier, a.email, a.attendee_type
             FROM attendees a
             WHERE a.event_id = :event_id AND a.status = 'active'
             ORDER BY a.full_name, a.attendee_code"
        );
        $statement->execute(['event_id' => (int) $event['id']]);
        $dayId = (int) $day['id'];
        $registrations = self::sessionRegistrations((int) $event['id'], $dayId);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $list = $registrations[(int) $row['id']][$dayId] ?? [];
            $rows[] = [
                $row['attendee_code'], $row['full_name'], $row['company'], $row['department'], $row['external_identifier'], $row['email'],
                $list !== [] ? 'Registered' : 'Not registered', $list[0]['registered_at'] ?? null, self::sessionList($list),
                $row['attendee_type'] !== null ? strtoupper((string) $row['attendee_type']) : null,
            ];
        }
        // "Internal/External" (migration 034) is appended so existing columns keep their positions.
        $xlsx = (new XlsxWriter())->addSheet(
            "Day {$day['day_number']} Attendees",
            ['Attendee Code', 'Full Name', 'Company', 'Cluster', 'Employee ID', 'Email', 'Registration Status', 'Registered At', 'Registration Sessions', 'Internal/External'],
            $rows
        );

        return self::xlsx($xlsx, "Day {$day['day_number']} Attendees.xlsx");
    }

    /**
     * Winners.xlsx (Phase 9.2): one sheet of Minor and Major draws in order,
     * with Event Day and Randomizer. VOID draws are kept with their reason.
     * Phase 9.4: "Exclusion Reset At/By" (appended columns) mark VALID draws
     * whose no-repeat exclusion an admin reset; a later win of the same
     * person on the same day + randomizer comes after that time.
     */
    public static function winnersXlsx(string $scope): Response
    {
        [$event, $day] = self::context($scope);
        $rows = [];
        foreach (RandomizerDraw::allForEvent((int) $event['id'], $day !== null ? (int) $day['id'] : null) as $row) {
            $rows[] = [
                self::dayCell($row), ucfirst((string) $row['randomizer_type']), $row['attendee_code'], $row['full_name'], $row['company'], $row['department'],
                $row['selected_at'], $row['drawn_by_name'], $row['voided_at'] !== null ? 'VOID' : 'VALID',
                $row['voided_at'], $row['voided_by_name'], $row['void_reason'],
                $row['reset_at'], $row['reset_at'] !== null ? $row['reset_by_name'] : null,
            ];
        }
        $xlsx = (new XlsxWriter())->addSheet(
            'Winners',
            ['Event Day', 'Randomizer', 'Attendee Code', 'Full Name', 'Company', 'Cluster', 'Drawn At', 'Drawn By', 'Status', 'Voided At', 'Voided By', 'Void Reason', 'Exclusion Reset At', 'Exclusion Reset By'],
            $rows
        );

        return self::xlsx($xlsx, $day !== null ? "Winners - Day {$day['day_number']}.xlsx" : 'Winners.xlsx');
    }

    /**
     * Active session registrations of an event (one day or all), earliest
     * first: [attendee_id][event_day_id] => list of {label, registered_at,
     * registered_by, counts_for_major, source}. One query; no per-row lookups.
     *
     * @return array<int, array<int, list<array<string, mixed>>>>
     */
    private static function sessionRegistrations(int $eventId, ?int $dayId): array
    {
        $sql = "SELECT r.attendee_id, r.event_day_id, r.registered_at, r.source, u.name AS registered_by,
                       s.label, s.counts_for_major
                FROM session_registrations r
                JOIN registration_sessions s ON s.id = r.registration_session_id
                LEFT JOIN users u ON u.id = r.registered_by
                WHERE r.event_id = :event_id AND r.status = 'active'";
        $params = ['event_id' => $eventId];
        if ($dayId !== null) {
            $sql .= ' AND r.event_day_id = :day_id';
            $params['day_id'] = $dayId;
        }
        $statement = Database::connection()->prepare($sql . ' ORDER BY r.registered_at, r.id');
        $statement->execute($params);
        $map = [];
        foreach ($statement->fetchAll() as $row) {
            $map[(int) $row['attendee_id']][(int) $row['event_day_id']][] = [
                'label' => $row['label'],
                'registered_at' => $row['registered_at'],
                'registered_by' => $row['registered_by'],
                'counts_for_major' => (bool) $row['counts_for_major'],
                'source' => $row['source'],
            ];
        }

        return $map;
    }

    /** "Oct 15 - 8:00 AM; Oct 15 - 5:00 PM (manual)" */
    private static function sessionList(array $list): string
    {
        return implode('; ', array_map(
            static fn (array $r): string => $r['label'] . ($r['source'] === 'manual' ? ' (manual)' : ''),
            $list
        ));
    }

    private static function xlsx(XlsxWriter $xlsx, string $filename): Response
    {
        return Response::raw(200, $xlsx->toString(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', [
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
        ]);
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
