<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\ImportBatch;
use App\Models\MajorEligibility;
use App\Utils\SpreadsheetReader;

/**
 * Major eligibility import (Phase 6): a Google Sheet of form responses,
 * exported as CSV/XLSX, is matched to attendees of the ACTIVE event.
 *
 * Flow (stateless, like the attendee import): parse -> preview -> import.
 * The file only grants eligibility; attendee data is never changed and the
 * spreadsheet contents are not stored.
 *
 * Matching (exact after normalisation: trim, collapse spaces, ignore case;
 * no fuzzy matching). Every mapped identifier that has a value is checked:
 *   attendee code, external identifier, email, full name + department.
 *   - any identifier matching 2+ attendees          -> ambiguous
 *   - identifiers pointing at different attendees   -> ambiguous
 *   - the one candidate has a different code / external ID / email than
 *     the row provides                              -> ambiguous (conflict)
 *   - nothing matches                               -> unmatched
 *   - exactly one, consistent candidate             -> matched
 * A matched attendee who is archived is reported as inactive and NOT made
 * eligible; one who is already eligible is reported as already eligible.
 */
final class MajorEligibilityImportService
{
    public const FIELDS = ['attendee_code', 'external_identifier', 'email', 'full_name', 'department'];
    private const LABELS = [
        'attendee_code' => 'Attendee Code',
        'external_identifier' => 'External Identifier',
        'email' => 'Email',
        'full_name' => 'Full Name',
        'department' => 'Department',
    ];
    private const CODE_HEADERS = ['attendeecode', 'attcode', 'code', 'registrationcode', 'ticketcode', 'eventcode', 'attendeeid'];

    /** @return array<string, mixed> */
    public static function parse(?array $file): array
    {
        // Reuses the Phase 2 upload validation (type, size, CSV/XLSX reading).
        $parsed = AttendeeImportService::parse($file);
        $parsed['suggestedMapping'] = self::suggestMapping($parsed['headers']);

        return $parsed;
    }

    /** @return array<string, mixed> */
    public static function analyse(int $eventId, array $payload): array
    {
        [$headers, $rows, $mapping] = self::readPayload($payload);
        $analysis = self::buildAnalysis($eventId, $rows, $mapping);
        unset($analysis['newAttendeeIds']);

        return $analysis;
    }

    /** @return array<string, mixed> */
    public static function commit(Request $request, int $eventId, array $payload): array
    {
        [$headers, $rows, $mapping] = self::readPayload($payload);
        $filename = self::safeFilename((string) ($payload['filename'] ?? 'responses'));

        $result = Database::transaction(static function (\PDO $pdo) use ($eventId, $headers, $rows, $mapping, $filename): array {
            $pdo->prepare('SELECT id FROM events WHERE id = :id FOR UPDATE')->execute(['id' => $eventId]);
            $analysis = self::buildAnalysis($eventId, $rows, $mapping);
            $summary = $analysis['summary'];

            $mappingByName = [];
            foreach ($mapping as $field => $index) {
                $mappingByName[$field] = $index !== null ? $headers[$index] : null;
            }

            $batchId = ImportBatch::createCompleted(
                $eventId,
                'major_entries',
                $filename,
                $summary['total'],
                0,
                $summary['total'] - $summary['matched'],
                ['fields' => $mappingByName],
                [
                    // Counts plus row numbers/reasons only - no spreadsheet contents.
                    'summary' => $summary,
                    'issues' => array_map(
                        static fn (array $row): array => ['rowNumber' => $row['rowNumber'], 'result' => $row['result'], 'reason' => $row['reason']],
                        array_slice(array_values(array_filter($analysis['rows'], static fn ($r) => !in_array($r['result'], ['matched', 'already_eligible'], true))), 0, 300)
                    ),
                ],
                AuthService::currentUserId()
            );

            $added = 0;
            foreach ($analysis['newAttendeeIds'] as $attendeeId) {
                if (MajorEligibility::add($eventId, $attendeeId, $batchId)) {
                    $added++;
                }
            }
            $pdo->prepare('UPDATE import_batches SET successful_rows = :n WHERE id = :id')->execute(['n' => $added, 'id' => $batchId]);

            return ['batchId' => $batchId, 'summary' => $summary, 'added' => $added];
        });

        AuditLogger::log(
            $request,
            'major.eligibility_imported',
            sprintf(
                'Major eligibility import "%s": %d rows, %d newly eligible, %d already eligible, %d unmatched, %d ambiguous, %d invalid.',
                $filename,
                $result['summary']['total'],
                $result['added'],
                $result['summary']['alreadyEligible'],
                $result['summary']['unmatched'],
                $result['summary']['ambiguous'],
                $result['summary']['invalid']
            ),
            $eventId,
            ['import_batch_id' => $result['batchId'], 'summary' => $result['summary']]
        );

        return ['importBatchId' => $result['batchId'], 'newlyEligible' => $result['added'], 'summary' => $result['summary']];
    }

    /**
     * @param list<string> $headers
     * @return array<string, int|null>
     */
    public static function suggestMapping(array $headers): array
    {
        $base = AttendeeImportService::suggestMapping($headers);
        $mapping = ['attendee_code' => null] + $base;
        $used = array_flip(array_filter($base, static fn ($i) => $i !== null));
        foreach ($headers as $index => $header) {
            $name = preg_replace('/[^a-z0-9]/', '', mb_strtolower($header)) ?? '';
            if (!isset($used[$index]) && in_array($name, self::CODE_HEADERS, true)) {
                $mapping['attendee_code'] = $index;
                break;
            }
        }

        return $mapping;
    }

    /**
     * @param list<array{rowNumber: int, cells: list<string>}> $rows
     * @param array<string, int|null> $mapping
     * @return array<string, mixed>
     */
    private static function buildAnalysis(int $eventId, array $rows, array $mapping): array
    {
        $attendees = [];
        $index = ['attendee_code' => [], 'external_identifier' => [], 'email' => [], 'name_department' => []];
        foreach (Attendee::identityRowsForEvent($eventId) as $row) {
            $id = (int) $row['id'];
            $keys = self::keys($row['attendee_code'], $row['external_identifier'], $row['email'], $row['full_name'], $row['department']);
            $attendees[$id] = ['row' => $row, 'keys' => $keys];
            foreach ($keys as $type => $value) {
                if ($value !== '') {
                    $index[$type][$value][] = $id;
                }
            }
        }
        $eligible = MajorEligibility::attendeeIdSet($eventId);

        $results = [];
        $newIds = [];
        $seenInFile = [];
        $counts = ['matched' => 0, 'alreadyEligible' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'inactive' => 0, 'invalid' => 0];

        foreach ($rows as $row) {
            $value = static fn (string $field): string => $mapping[$field] !== null ? SpreadsheetReader::cleanCell($row['cells'][$mapping[$field]] ?? '') : '';
            $raw = [];
            foreach (self::FIELDS as $field) {
                $raw[$field] = $value($field);
            }
            $keys = self::keys($raw['attendee_code'], $raw['external_identifier'], $raw['email'], $raw['full_name'], $raw['department']);
            $display = ['rowNumber' => $row['rowNumber'], 'values' => $raw];

            if (implode('', $keys) === '') {
                $results[] = $display + self::result('invalid', 'No identifier value in this row (code, external ID, email, or name + department).');
                $counts['invalid']++;
                continue;
            }

            [$outcome, $attendeeId, $reason] = self::match($keys, $index, $attendees);
            if ($outcome !== 'matched') {
                $results[] = $display + self::result($outcome, $reason);
                $counts[$outcome]++;
                continue;
            }

            $attendee = $attendees[$attendeeId]['row'];
            $ref = ['attendeeCode' => $attendee['attendee_code'], 'fullName' => $attendee['full_name'], 'department' => $attendee['department']];

            if ($attendee['status'] !== Attendee::STATUS_ACTIVE) {
                $results[] = $display + self::result('inactive', 'Matches an archived attendee; not made eligible.', $ref);
                $counts['inactive']++;
            } elseif (isset($eligible[$attendeeId]) || isset($seenInFile[$attendeeId])) {
                $reason = isset($seenInFile[$attendeeId]) ? "Same attendee as row {$seenInFile[$attendeeId]}." : 'Already Major Eligible.';
                $results[] = $display + self::result('already_eligible', $reason, $ref);
                $counts['alreadyEligible']++;
            } else {
                $seenInFile[$attendeeId] = $row['rowNumber'];
                $newIds[] = $attendeeId;
                $results[] = $display + self::result('matched', $reason, $ref);
                $counts['matched']++;
            }
        }

        $total = count($rows);

        return [
            'summary' => ['total' => $total, 'valid' => $total - $counts['invalid']] + $counts,
            'rows' => $results,
            'newAttendeeIds' => $newIds,
        ];
    }

    /**
     * @param array<string, string> $keys
     * @param array<string, array<string, list<int>>> $index
     * @param array<int, array{row: array<string, mixed>, keys: array<string, string>}> $attendees
     * @return array{0: string, 1: ?int, 2: string}
     */
    private static function match(array $keys, array $index, array $attendees): array
    {
        $labels = ['attendee_code' => 'Attendee code', 'external_identifier' => 'External ID', 'email' => 'Email', 'name_department' => 'Name + department'];
        $candidates = [];
        $usedBy = [];

        foreach ($keys as $type => $value) {
            if ($value === '') {
                continue;
            }
            $ids = array_values(array_unique($index[$type][$value] ?? []));
            if (count($ids) > 1) {
                return ['ambiguous', null, "{$labels[$type]} matches " . count($ids) . ' attendees.'];
            }
            if (count($ids) === 1) {
                $candidates[$ids[0]] = true;
                $usedBy[] = $labels[$type];
            }
        }

        if ($candidates === []) {
            return ['unmatched', null, 'No attendee in this event matches.'];
        }
        if (count($candidates) > 1) {
            return ['ambiguous', null, 'The identifiers in this row point to different attendees.'];
        }

        $id = (int) array_key_first($candidates);
        foreach ($keys as $type => $value) {
            if ($type === 'name_department') {
                continue; // names are often typed differently in forms; strong IDs decide
            }
            $theirs = $attendees[$id]['keys'][$type];
            if ($value !== '' && $theirs !== '' && $value !== $theirs) {
                return ['ambiguous', null, "{$labels[$type]} in the file differs from the attendee record; check manually."];
            }
        }

        return ['matched', $id, 'Matched by ' . implode(', ', $usedBy) . '.'];
    }

    /** @return array<string, string> normalised identifiers */
    private static function keys(?string $code, ?string $ext, ?string $email, ?string $name, ?string $department): array
    {
        $n = static fn (?string $v): string => AttendeeImportService::normaliseText($v);
        $nameKey = $n($name) !== '' && $n($department) !== '' ? $n($name) . '|' . $n($department) : '';

        return [
            'attendee_code' => $n($code),
            'external_identifier' => $n($ext),
            'email' => $n($email),
            'name_department' => $nameKey,
        ];
    }

    /** @return array<string, mixed> */
    private static function result(string $result, string $reason, ?array $attendee = null): array
    {
        return ['result' => $result, 'reason' => $reason, 'attendee' => $attendee];
    }

    /**
     * @return array{0: list<string>, 1: list<array{rowNumber: int, cells: list<string>}>, 2: array<string, int|null>}
     */
    private static function readPayload(array $payload): array
    {
        $headers = $payload['headers'] ?? null;
        $rows = $payload['rows'] ?? null;
        $mapping = $payload['mapping'] ?? null;

        if (!is_array($headers) || $headers === [] || !array_is_list($headers) || count($headers) > SpreadsheetReader::MAX_COLUMNS) {
            throw HttpException::badRequest('Invalid import data: headers are missing. Upload the file again.');
        }
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > SpreadsheetReader::MAX_ROWS) {
            throw HttpException::badRequest('Invalid import data: rows are missing. Upload the file again.');
        }
        if ($rows === []) {
            throw HttpException::validation(['file' => ['The file has no data rows.']]);
        }
        if (!is_array($mapping)) {
            throw HttpException::validation(['mapping' => ['Map at least one identifier column.']]);
        }

        $headers = array_map(static fn ($h): string => mb_substr(SpreadsheetReader::cleanCell($h), 0, 100), $headers);
        $width = count($headers);
        $clean = [];
        $used = [];
        $errors = [];
        foreach (self::FIELDS as $field) {
            $value = $mapping[$field] ?? null;
            if ($value === null || $value === '') {
                $clean[$field] = null;
                continue;
            }
            if (!is_int($value) || $value < 0 || $value >= $width) {
                $errors[$field][] = 'Invalid column selected.';
                continue;
            }
            if (isset($used[$value])) {
                $errors[$field][] = 'The column "' . $headers[$value] . '" is already mapped to ' . self::LABELS[$used[$value]] . '.';
                continue;
            }
            $used[$value] = $field;
            $clean[$field] = $value;
        }
        if ($errors === []) {
            $hasStrong = $clean['attendee_code'] !== null || $clean['external_identifier'] !== null || $clean['email'] !== null;
            $hasNamePair = $clean['full_name'] !== null && $clean['department'] !== null;
            if (!$hasStrong && !$hasNamePair) {
                $errors['mapping'][] = 'Map at least one identifier: Attendee Code, External Identifier, Email, or both Full Name and Department.';
            } elseif ($clean['full_name'] !== null xor $clean['department'] !== null) {
                $missing = $clean['full_name'] === null ? 'full_name' : 'department';
                if (!$hasStrong) {
                    $errors[$missing][] = 'Name matching needs both Full Name and Department.';
                }
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors, 'Check the column mapping.');
        }

        $cleanRows = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['rowNumber'], $row['cells']) || !is_int($row['rowNumber']) || !is_array($row['cells'])) {
                throw HttpException::badRequest('Invalid import data. Upload the file again.');
            }
            $cells = array_map(static fn ($c): string => is_scalar($c) ? (string) $c : '', array_slice(array_values($row['cells']), 0, $width));
            $cleanRows[] = ['rowNumber' => $row['rowNumber'], 'cells' => array_pad($cells, $width, '')];
        }

        return [$headers, $cleanRows, $clean];
    }

    private static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';

        return mb_substr($name !== '' ? $name : 'responses', 0, 255);
    }
}
