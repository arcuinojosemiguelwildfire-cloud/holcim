<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Response;
use App\Models\RandomizerDraw;

/**
 * CSV exports for the ACTIVE event only (Phase 7). The event is always
 * resolved server-side; no event ID is accepted from the browser.
 */
final class ReportService
{
    public static function registration(): Response
    {
        $event = AttendeeService::activeEventOrFail();
        $statement = Database::connection()->prepare(
            "SELECT a.attendee_code, a.full_name, a.department, a.email, a.status,
                    r.scanned_at, u.name AS scanned_by
             FROM attendees a
             LEFT JOIN registration_scans r ON r.attendee_id = a.id AND r.event_id = a.event_id
             LEFT JOIN users u ON u.id = r.scanner_user_id
             WHERE a.event_id = :event_id
             ORDER BY a.attendee_code"
        );
        $statement->execute(['event_id' => (int) $event['id']]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                $row['attendee_code'], $row['full_name'], $row['department'], $row['email'],
                $row['scanned_at'] !== null ? 'Registered' : 'Not registered',
                $row['scanned_at'], $row['scanned_by'], ucfirst((string) $row['status']),
            ];
        }

        return self::csv(
            $event,
            'registration',
            ['Attendee Code', 'Full Name', 'Department', 'Email', 'Registration Status', 'Registered At', 'Registered By', 'Attendee Status'],
            $rows
        );
    }

    public static function majorEligibility(): Response
    {
        $event = AttendeeService::activeEventOrFail();
        $statement = Database::connection()->prepare(
            "SELECT a.attendee_code, a.full_name, a.department, a.email, a.status, m.imported_at
             FROM attendees a
             LEFT JOIN major_eligibility m ON m.attendee_id = a.id AND m.event_id = a.event_id
             WHERE a.event_id = :event_id
             ORDER BY (m.id IS NULL), a.attendee_code"
        );
        $statement->execute(['event_id' => (int) $event['id']]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            // Eligible for the draw = imported AND attendee active (same rule as the randomizer).
            $eligible = $row['imported_at'] !== null && $row['status'] === 'active';
            $rows[] = [
                $row['attendee_code'], $row['full_name'], $row['department'], $row['email'],
                $eligible ? 'Yes' : 'No', $row['imported_at'], ucfirst((string) $row['status']),
            ];
        }

        return self::csv(
            $event,
            'major-eligibility',
            ['Attendee Code', 'Full Name', 'Department', 'Email', 'Major Eligible', 'Imported At', 'Attendee Status'],
            $rows
        );
    }

    public static function draws(): Response
    {
        $event = AttendeeService::activeEventOrFail();
        $rows = [];
        foreach (RandomizerDraw::allForEvent((int) $event['id']) as $row) {
            $rows[] = [
                ucfirst((string) $row['randomizer_type']), $row['attendee_code'], $row['full_name'], $row['department'],
                $row['selected_at'], $row['drawn_by_name'], $row['voided_at'] !== null ? 'VOID' : 'Valid',
                $row['voided_at'], $row['voided_by_name'], $row['void_reason'],
            ];
        }

        return self::csv(
            $event,
            'draw-winners',
            ['Draw Type', 'Attendee Code', 'Full Name', 'Department', 'Drawn At', 'Drawn By', 'Status', 'Voided At', 'Voided By', 'Void Reason'],
            $rows
        );
    }

    /**
     * @param array<string, mixed> $event
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     */
    private static function csv(array $event, string $name, array $headers, array $rows): Response
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
        $filename = "{$slug}-{$name}-" . date('Y-m-d-Hi') . '.csv';

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
