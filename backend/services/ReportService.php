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
            "SELECT d.day_number, d.event_date AS day_date,
                    a.attendee_code, a.full_name, a.company, a.department, a.email, a.status,
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
                self::dayCell($row), $row['attendee_code'], $row['full_name'], $row['company'], $row['department'], $row['email'],
                $row['scanned_at'] !== null ? 'Registered' : 'Not registered',
                $row['scanned_at'], $row['scanned_by'], ucfirst((string) $row['status']),
            ];
        }

        return self::csv(
            $event,
            $day,
            'registration',
            ['Event Day', 'Attendee Code', 'Full Name', 'Company', 'Cluster', 'Email', 'Registration Status', 'Registered At', 'Registered By', 'Attendee Status'],
            $rows
        );
    }

    /**
     * Raffle eligibility per attendee per day (Phase 9.2): registration makes
     * an attendee eligible for BOTH Minor and Major; a manual addition counts
     * for its own randomizer only. "Won" = valid (not void) draw that day.
     */
    public static function eligibility(string $scope): Response
    {
        [$event, $day] = self::context($scope);
        [$dayJoin, $params] = self::dayJoin($event, $day);
        $statement = Database::connection()->prepare(
            "SELECT d.day_number, d.event_date AS day_date,
                    a.attendee_code, a.full_name, a.company, a.department, a.email, a.status,
                    (r.id IS NOT NULL) AS registered,
                    (mm.id IS NOT NULL) AS minor_manual,
                    (mj.id IS NOT NULL) AS major_manual,
                    EXISTS (SELECT 1 FROM randomizer_draws w WHERE w.event_day_id = d.id AND w.attendee_id = a.id
                            AND w.randomizer_type = 'minor' AND w.voided_at IS NULL) AS won_minor,
                    EXISTS (SELECT 1 FROM randomizer_draws w2 WHERE w2.event_day_id = d.id AND w2.attendee_id = a.id
                            AND w2.randomizer_type = 'major' AND w2.voided_at IS NULL) AS won_major
             FROM attendees a
             {$dayJoin}
             LEFT JOIN registration_scans r ON r.attendee_id = a.id AND r.event_day_id = d.id
             LEFT JOIN minor_manual_entries mm ON mm.attendee_id = a.id AND mm.event_day_id = d.id
             LEFT JOIN major_eligibility mj ON mj.attendee_id = a.id AND mj.event_day_id = d.id AND mj.source = 'manual'
             WHERE a.event_id = :event_id
             ORDER BY d.day_number, (r.id IS NULL), a.attendee_code"
        );
        $statement->execute($params);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $active = $row['status'] === 'active';
            $source = static fn (bool $manual): string => $row['registered'] ? 'Registration' : ($manual ? 'Manual' : '');
            $minor = $active && ($row['registered'] || $row['minor_manual']);
            $major = $active && ($row['registered'] || $row['major_manual']);
            $rows[] = [
                self::dayCell($row), $row['attendee_code'], $row['full_name'], $row['company'], $row['department'], $row['email'],
                $row['registered'] ? 'Yes' : 'No',
                $minor ? 'Yes' : 'No', $minor ? $source((bool) $row['minor_manual']) : '', $row['won_minor'] ? 'Yes' : 'No',
                $major ? 'Yes' : 'No', $major ? $source((bool) $row['major_manual']) : '', $row['won_major'] ? 'Yes' : 'No',
                ucfirst((string) $row['status']),
            ];
        }

        return self::csv(
            $event,
            $day,
            'raffle-eligibility',
            ['Event Day', 'Attendee Code', 'Full Name', 'Company', 'Cluster', 'Email', 'Registered',
                'Minor Eligible', 'Minor Source', 'Won Minor', 'Major Eligible', 'Major Source', 'Won Major', 'Attendee Status'],
            $rows
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
            ];
        }

        return self::csv(
            $event,
            $day,
            'draw-winners',
            ['Event Day', 'Draw Type', 'Attendee Code', 'Full Name', 'Company', 'Cluster', 'Drawn At', 'Drawn By', 'Status', 'Voided At', 'Voided By', 'Void Reason'],
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
            "SELECT a.attendee_code, a.full_name, a.company, a.department, a.external_identifier, a.email, r.scanned_at
             FROM attendees a
             LEFT JOIN registration_scans r ON r.attendee_id = a.id AND r.event_day_id = :day_id
             WHERE a.event_id = :event_id AND a.status = 'active'
             ORDER BY a.full_name, a.attendee_code"
        );
        $statement->execute(['day_id' => (int) $day['id'], 'event_id' => (int) $event['id']]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                $row['attendee_code'], $row['full_name'], $row['company'], $row['department'], $row['external_identifier'], $row['email'],
                $row['scanned_at'] !== null ? 'Registered' : 'Not registered', $row['scanned_at'],
            ];
        }
        $xlsx = (new XlsxWriter())->addSheet(
            "Day {$day['day_number']} Attendees",
            ['Attendee Code', 'Full Name', 'Company', 'Cluster', 'Employee ID', 'Email', 'Registration Status', 'Registered At'],
            $rows
        );

        return self::xlsx($xlsx, "Day {$day['day_number']} Attendees.xlsx");
    }

    /**
     * Winners.xlsx (Phase 9.2): one sheet of Minor and Major draws in order,
     * with Event Day and Randomizer. VOID draws are kept with their reason.
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
            ];
        }
        $xlsx = (new XlsxWriter())->addSheet(
            'Winners',
            ['Event Day', 'Randomizer', 'Attendee Code', 'Full Name', 'Company', 'Cluster', 'Drawn At', 'Drawn By', 'Status', 'Voided At', 'Voided By', 'Void Reason'],
            $rows
        );

        return self::xlsx($xlsx, $day !== null ? "Winners - Day {$day['day_number']}.xlsx" : 'Winners.xlsx');
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
