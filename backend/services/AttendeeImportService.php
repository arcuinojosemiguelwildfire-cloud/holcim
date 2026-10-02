<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\ImportBatch;
use App\Utils\SpreadsheetReader;

/**
 * Attendee import: UPLOAD -> READ -> PREVIEW -> MAPPING -> VALIDATION ->
 * DUPLICATE CHECK -> CONFIRMATION -> DATABASE.
 *
 * The flow is stateless: parse() returns the file's headers and rows; the
 * client sends them back with a column mapping to analyse() (preview) and
 * commit() (import). Every step re-validates on the server, so nothing the
 * client sends is trusted.
 *
 * Duplicate rules (conservative, no fuzzy matching), checked in order:
 *   1. External identifier (case-insensitive)
 *   2. Email (case-insensitive) unless both sides have different external IDs
 *   3. Normalised full name + department unless both sides have different
 *      external IDs or different emails. Department is optional: a blank
 *      department only matches another blank department (same name), so
 *      "Juan Delacruz / HR" and "Juan Delacruz / (blank)" are different rows.
 * Duplicates are skipped: existing attendees are never modified, and their
 * attendee codes stay stable.
 */
final class AttendeeImportService
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    public const FIELDS = ['full_name', 'department', 'email', 'external_identifier'];
    /** Full Name is the only required attendee field; everything else is optional. */
    private const REQUIRED = ['full_name'];
    private const LABELS = [
        'full_name' => 'Full Name',
        'department' => 'Department',
        'email' => 'Email',
        'external_identifier' => 'Employee ID / External Identifier',
    ];
    private const MAX_LENGTHS = ['full_name' => 200, 'department' => 150, 'email' => 190, 'external_identifier' => 190];

    /** Normalised header names that map directly to a field. */
    private const SYNONYMS = [
        'full_name' => ['fullname', 'name', 'employeename', 'completename', 'attendeename', 'participantname',
            'staffname', 'nameofemployee', 'employeefullname', 'nameofattendee', 'guestname'],
        'department' => ['department', 'dept', 'departmentname', 'deptname', 'division', 'unit', 'section',
            'team', 'businessunit', 'group', 'office', 'departmentdivision'],
        'email' => ['email', 'emailaddress', 'mail', 'workemail', 'companyemail', 'officialemail', 'emailadd'],
        'external_identifier' => ['employeeid', 'employeeno', 'employeenumber', 'empid', 'empno', 'idno', 'idnumber',
            'id', 'staffid', 'staffno', 'badgeno', 'badgenumber', 'employeecode', 'empcode', 'personnelno',
            'externalid', 'externalidentifier', 'employeeidno'],
    ];

    /**
     * Validates and reads an uploaded file.
     *
     * @param array<string, mixed>|null $file entry from $_FILES
     * @return array<string, mixed>
     */
    public static function parse(?array $file): array
    {
        if ($file === null || !isset($file['error']) || is_array($file['error'])) {
            throw HttpException::validation(['file' => ['Choose a CSV or XLSX file to upload.']]);
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw HttpException::validation(['file' => ['The file is larger than the server allows.']]);
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw HttpException::validation(['file' => ['The upload failed. Please try again.']]);
        }
        if ((int) $file['size'] > self::MAX_UPLOAD_BYTES) {
            throw HttpException::validation(['file' => ['The file must be 5 MB or smaller.']]);
        }
        if ((int) $file['size'] === 0) {
            throw HttpException::validation(['file' => ['The file is empty.']]);
        }

        $filename = self::safeFilename((string) $file['name']);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $type = self::detectType((string) $file['tmp_name'], $extension);

        $sheet = SpreadsheetReader::read((string) $file['tmp_name'], $type);

        return [
            'filename' => $filename,
            'fileType' => $type,
            'headers' => $sheet['headers'],
            'rows' => $sheet['rows'],
            'totalRows' => count($sheet['rows']),
            'suggestedMapping' => self::suggestMapping($sheet['headers']),
        ];
    }

    /**
     * Preview: validation + duplicate check, no writes.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function analyse(int $eventId, array $payload): array
    {
        [$headers, $rows, $mapping] = self::readPayload($payload);

        return self::buildAnalysis($eventId, $headers, $rows, $mapping);
    }

    /**
     * Imports only new, valid rows. Runs the analysis again inside a
     * transaction (event row locked) so concurrent imports cannot create
     * duplicates or reuse attendee codes.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function commit(Request $request, int $eventId, array $payload): array
    {
        [$headers, $rows, $mapping] = self::readPayload($payload);
        $filename = self::safeFilename((string) ($payload['filename'] ?? 'import'));

        $result = Database::transaction(static function (\PDO $pdo) use ($eventId, $headers, $rows, $mapping, $filename, $request) {
            $lock = $pdo->prepare('SELECT id FROM events WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $eventId]);

            $analysis = self::buildAnalysis($eventId, $headers, $rows, $mapping, includeAllRows: true);
            $summary = $analysis['summary'];

            $mappingByName = [];
            foreach ($mapping as $field => $index) {
                $mappingByName[$field] = $index !== null ? $headers[$index] : null;
            }

            $batchId = ImportBatch::createCompleted(
                $eventId,
                ImportBatch::TYPE_ATTENDEES,
                $filename,
                $summary['total'],
                0,
                $summary['invalid'],
                ['fields' => $mappingByName, 'headers' => $headers],
                [
                    'invalid' => array_slice($analysis['invalid'], 0, 200),
                    'duplicates' => array_slice($analysis['duplicates'], 0, 200),
                ],
                AuthService::currentUserId()
            );

            $next = Attendee::maxCodeNumber($eventId);
            $created = [];
            foreach ($analysis['newRecords'] as $record) {
                $code = Attendee::formatCode(++$next);
                Attendee::create($eventId, $code, $record, $batchId);
                $created[] = ['rowNumber' => $record['rowNumber'], 'attendeeCode' => $code, 'fullName' => $record['full_name']];
            }

            $pdo->prepare('UPDATE import_batches SET successful_rows = :n WHERE id = :id')
                ->execute(['n' => count($created), 'id' => $batchId]);

            return ['batchId' => $batchId, 'summary' => $summary, 'created' => $created];
        });

        $summary = $result['summary'];
        AuditLogger::log(
            $request,
            'attendee.imported',
            sprintf(
                'Imported %d attendee(s) from "%s" (%d rows, %d invalid, %d duplicate).',
                count($result['created']),
                $filename,
                $summary['total'],
                $summary['invalid'],
                $summary['duplicates']
            ),
            $eventId,
            ['import_batch_id' => $result['batchId'], 'summary' => $summary]
        );

        return [
            'importBatchId' => $result['batchId'],
            'summary' => $summary,
            'imported' => count($result['created']),
            'created' => array_slice($result['created'], 0, 50),
        ];
    }

    /**
     * @param list<string> $headers
     * @return array<string, int|null> field => column index
     */
    public static function suggestMapping(array $headers): array
    {
        $normalised = array_map(
            static fn (string $h): string => preg_replace('/[^a-z0-9]/', '', mb_strtolower($h)) ?? '',
            $headers
        );
        $mapping = array_fill_keys(self::FIELDS, null);
        $used = [];

        // Pass 1: exact synonym match.
        foreach (self::FIELDS as $field) {
            foreach ($normalised as $index => $name) {
                if (!isset($used[$index]) && in_array($name, self::SYNONYMS[$field], true)) {
                    $mapping[$field] = $index;
                    $used[$index] = true;
                    break;
                }
            }
        }

        // Pass 2: keyword match for anything still unmapped.
        $rules = [
            'email' => static fn (string $n): bool => str_contains($n, 'email') || str_contains($n, 'mail'),
            'department' => static fn (string $n): bool => str_contains($n, 'department') || str_contains($n, 'dept') || str_contains($n, 'division'),
            'external_identifier' => static fn (string $n): bool => (str_contains($n, 'employee') || str_contains($n, 'emp') || str_contains($n, 'staff'))
                && (str_ends_with($n, 'id') || str_ends_with($n, 'no') || str_contains($n, 'number') || str_contains($n, 'code')),
            'full_name' => static fn (string $n): bool => str_contains($n, 'name')
                && !preg_match('/dept|department|company|email|user|first|last|middle|nick|division/', $n),
        ];
        foreach ($rules as $field => $matches) {
            if ($mapping[$field] !== null) {
                continue;
            }
            foreach ($normalised as $index => $name) {
                if (!isset($used[$index]) && $matches($name)) {
                    $mapping[$field] = $index;
                    $used[$index] = true;
                    break;
                }
            }
        }

        return $mapping;
    }

    public static function normaliseText(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
    }

    /**
     * @param list<string> $headers
     * @param list<array{rowNumber: int, cells: list<string>}> $rows
     * @param array<string, int|null> $mapping
     * @return array<string, mixed>
     */
    private static function buildAnalysis(int $eventId, array $headers, array $rows, array $mapping, bool $includeAllRows = false): array
    {
        // Index existing attendees of the event (all statuses).
        $existingByExt = $existingByEmail = $existingByName = [];
        foreach (Attendee::identityRowsForEvent($eventId) as $existing) {
            $identity = self::identity($existing['full_name'], $existing['department'], $existing['email'], $existing['external_identifier']);
            $entry = ['identity' => $identity, 'ref' => [
                'type' => 'existing',
                'attendeeCode' => $existing['attendee_code'],
                'fullName' => $existing['full_name'],
                'status' => $existing['status'],
            ]];
            self::indexIdentity($entry, $existingByExt, $existingByEmail, $existingByName);
        }

        $fileByExt = $fileByEmail = $fileByName = [];
        $newRecords = $invalid = $duplicates = [];
        $mappedIndexes = array_filter($mapping, static fn ($i) => $i !== null);

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $values = [];
            foreach (self::FIELDS as $field) {
                $index = $mapping[$field];
                $values[$field] = $index !== null ? SpreadsheetReader::cleanCell($cells[$index] ?? '') : '';
            }

            $display = [
                'rowNumber' => $row['rowNumber'],
                'fullName' => $values['full_name'],
                'department' => $values['department'],
                'email' => $values['email'],
                'externalIdentifier' => $values['external_identifier'],
            ];

            $errors = self::validateRow($values);
            if ($errors !== []) {
                $invalid[] = $display + ['reasons' => $errors];
                continue;
            }

            $identity = self::identity($values['full_name'], $values['department'], $values['email'], $values['external_identifier']);

            $match = self::findMatch($identity, $existingByExt, $existingByEmail, $existingByName)
                ?? self::findMatch($identity, $fileByExt, $fileByEmail, $fileByName);
            if ($match !== null) {
                $duplicates[] = $display + ['matchedBy' => $match['by'], 'matches' => $match['ref']];
                continue;
            }

            $entry = ['identity' => $identity, 'ref' => [
                'type' => 'file',
                'rowNumber' => $row['rowNumber'],
                'fullName' => $values['full_name'],
            ]];
            self::indexIdentity($entry, $fileByExt, $fileByEmail, $fileByName);

            $extra = [];
            foreach ($headers as $index => $header) {
                if (!in_array($index, $mappedIndexes, true) && ($cells[$index] ?? '') !== '') {
                    $extra[$header] = SpreadsheetReader::cleanCell($cells[$index]);
                }
            }

            $newRecords[] = [
                'rowNumber' => $row['rowNumber'],
                'full_name' => $values['full_name'],
                'department' => $values['department'] !== '' ? $values['department'] : null,
                'email' => $values['email'] !== '' ? mb_strtolower($values['email']) : null,
                'external_identifier' => $values['external_identifier'] !== '' ? $values['external_identifier'] : null,
                'extra_data' => $extra,
            ];
        }

        $summary = [
            'total' => count($rows),
            'valid' => count($newRecords),
            'invalid' => count($invalid),
            'duplicates' => count($duplicates),
            'duplicatesExisting' => count(array_filter($duplicates, static fn ($d) => $d['matches']['type'] === 'existing')),
            'duplicatesInFile' => count(array_filter($duplicates, static fn ($d) => $d['matches']['type'] === 'file')),
        ];

        $result = ['summary' => $summary, 'invalid' => $invalid, 'duplicates' => $duplicates];
        if ($includeAllRows) {
            $result['newRecords'] = $newRecords;
        } else {
            $result['newPreview'] = array_map(static fn (array $r): array => [
                'rowNumber' => $r['rowNumber'],
                'fullName' => $r['full_name'],
                'department' => $r['department'],
                'email' => $r['email'],
                'externalIdentifier' => $r['external_identifier'],
            ], array_slice($newRecords, 0, 100));
        }

        return $result;
    }

    /**
     * @param array<string, string> $values
     * @return list<string>
     */
    private static function validateRow(array $values): array
    {
        $errors = [];
        foreach (self::REQUIRED as $field) {
            if ($values[$field] === '') {
                $errors[] = self::LABELS[$field] . ' is required.';
            }
        }
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($values[$field]) > $max) {
                $errors[] = self::LABELS[$field] . " may not be longer than {$max} characters.";
            }
        }
        if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Email "' . mb_substr($values['email'], 0, 60) . '" is not a valid email address.';
        }

        return $errors;
    }

    /** @return array{ext: string, email: string, name: string} */
    private static function identity(?string $name, ?string $department, ?string $email, ?string $ext): array
    {
        return [
            'ext' => self::normaliseText($ext),
            'email' => self::normaliseText($email),
            'name' => self::normaliseText($name) . '|' . self::normaliseText($department),
        ];
    }

    /**
     * @param array{identity: array{ext: string, email: string, name: string}, ref: array<string, mixed>} $entry
     * @param array<string, mixed> $byExt
     * @param array<string, mixed> $byEmail
     * @param array<string, list<mixed>> $byName
     */
    private static function indexIdentity(array $entry, array &$byExt, array &$byEmail, array &$byName): void
    {
        $identity = $entry['identity'];
        if ($identity['ext'] !== '') {
            $byExt[$identity['ext']] ??= $entry;
        }
        if ($identity['email'] !== '') {
            $byEmail[$identity['email']] ??= $entry;
        }
        $byName[$identity['name']][] = $entry;
    }

    /**
     * @param array{ext: string, email: string, name: string} $identity
     * @param array<string, mixed> $byExt
     * @param array<string, mixed> $byEmail
     * @param array<string, list<mixed>> $byName
     * @return array{by: string, ref: array<string, mixed>}|null
     */
    private static function findMatch(array $identity, array $byExt, array $byEmail, array $byName): ?array
    {
        if ($identity['ext'] !== '' && isset($byExt[$identity['ext']])) {
            return ['by' => 'external_identifier', 'ref' => $byExt[$identity['ext']]['ref']];
        }

        if ($identity['email'] !== '' && isset($byEmail[$identity['email']])) {
            $other = $byEmail[$identity['email']]['identity'];
            if (!self::conflicts($identity['ext'], $other['ext'])) {
                return ['by' => 'email', 'ref' => $byEmail[$identity['email']]['ref']];
            }
        }

        foreach ($byName[$identity['name']] ?? [] as $candidate) {
            $other = $candidate['identity'];
            if (!self::conflicts($identity['ext'], $other['ext']) && !self::conflicts($identity['email'], $other['email'])) {
                return ['by' => 'name_department', 'ref' => $candidate['ref']];
            }
        }

        return null;
    }

    /** Two identifiers conflict only when both are present and different. */
    private static function conflicts(string $a, string $b): bool
    {
        return $a !== '' && $b !== '' && $a !== $b;
    }

    /**
     * Validates the client payload shape (headers, rows, mapping).
     *
     * @param array<string, mixed> $payload
     * @return array{0: list<string>, 1: list<array{rowNumber: int, cells: list<string>}>, 2: array<string, int|null>}
     */
    private static function readPayload(array $payload): array
    {
        $headers = $payload['headers'] ?? null;
        $rows = $payload['rows'] ?? null;
        $mapping = $payload['mapping'] ?? null;

        if (!is_array($headers) || $headers === [] || count($headers) > SpreadsheetReader::MAX_COLUMNS || !array_is_list($headers)) {
            throw HttpException::badRequest('Invalid import data: headers are missing. Upload the file again.');
        }
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > SpreadsheetReader::MAX_ROWS) {
            throw HttpException::badRequest('Invalid import data: rows are missing. Upload the file again.');
        }
        if ($rows === []) {
            throw HttpException::validation(['file' => ['The file has no data rows.']]);
        }
        if (!is_array($mapping)) {
            throw HttpException::validation(['mapping' => ['Map the file columns to attendee fields.']]);
        }

        $headers = array_map(static fn ($h): string => mb_substr(SpreadsheetReader::cleanCell($h), 0, 100), $headers);
        $width = count($headers);

        $cleanMapping = [];
        $errors = [];
        $used = [];
        foreach (self::FIELDS as $field) {
            $value = $mapping[$field] ?? null;
            if ($value === null || $value === '') {
                $cleanMapping[$field] = null;
                if (in_array($field, self::REQUIRED, true)) {
                    $errors[$field][] = 'Choose the column that contains ' . self::LABELS[$field] . '.';
                }
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
            $cleanMapping[$field] = $value;
        }
        if ($errors !== []) {
            throw HttpException::validation($errors, 'Check the column mapping.');
        }

        $cleanRows = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['rowNumber'], $row['cells']) || !is_int($row['rowNumber']) || !is_array($row['cells'])) {
                throw HttpException::badRequest('Invalid import data. Upload the file again.');
            }
            $cells = array_map(
                static fn ($c): string => is_scalar($c) ? (string) $c : '',
                array_slice(array_values($row['cells']), 0, $width)
            );
            $cleanRows[] = ['rowNumber' => $row['rowNumber'], 'cells' => array_pad($cells, $width, '')];
        }

        return [$headers, $cleanRows, $cleanMapping];
    }

    private static function detectType(string $tmpPath, string $extension): string
    {
        if ($extension === 'xls') {
            throw HttpException::validation(['file' => ['Old Excel (.xls) files are not supported. In Excel choose File > Save As > Excel Workbook (.xlsx) or CSV, then upload again.']]);
        }

        $head = (string) file_get_contents($tmpPath, false, null, 0, 8);
        $isZip = str_starts_with($head, "PK\x03\x04");

        if ($extension === 'xlsx') {
            if (!$isZip) {
                throw HttpException::validation(['file' => ['This file is not a valid .xlsx workbook.']]);
            }

            return 'xlsx';
        }
        if ($extension === 'csv' || $extension === 'txt') {
            if ($isZip || str_starts_with($head, "\xD0\xCF\x11\xE0") || str_contains($head, "\x00")) {
                throw HttpException::validation(['file' => ['This file is not a plain-text CSV. If it is an Excel file, save it as .xlsx.']]);
            }

            return 'csv';
        }

        throw HttpException::validation(['file' => ['Only .csv and .xlsx files are supported.']]);
    }

    private static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';

        return mb_substr($name !== '' ? $name : 'import', 0, 255);
    }
}
