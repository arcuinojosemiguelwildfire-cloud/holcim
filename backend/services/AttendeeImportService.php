<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Attendee;
use App\Models\ImportBatch;
use App\Utils\SpreadsheetReader;
use App\Utils\XlsxWriter;

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
 *   3. Normalised full name + company + cluster (attendees.department) unless
 *      both sides have different external IDs or different emails. Company and
 *      cluster are optional: a blank value only matches another blank value, so
 *      "Juan Delacruz / ABC / North" and "Juan Delacruz / XYZ / North" are
 *      different people.
 * Duplicates are skipped: existing attendees are never modified, and their
 * attendee codes stay stable.
 *
 * Standard guest sheet (client format):
 *   GUEST NO. | SURNAME | FIRST NAME | MIDDLE NAME | INTERNAL/EXTERNAL | DESIGNATION / COMPANY
 * GUEST NO. -> external_identifier (text, leading zeros kept; never the
 * attendee code or QR token), DESIGNATION / COMPANY -> company,
 * INTERNAL/EXTERNAL -> attendee_type ('internal' | 'external'), and the
 * name parts are combined into Full Name as FIRST + MIDDLE + SURNAME.
 *
 * Name precedence: a mapped Full Name column always wins. The name-part
 * fields are then ignored (their columns are kept as extra information, as
 * before), so a name is never combined twice. Without a Full Name column,
 * First Name and Surname must be mapped (Middle Name is optional) and every
 * row needs both a first name and a surname.
 */
final class AttendeeImportService
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    /**
     * Mapping fields. first_name / middle_name / surname are not columns:
     * they are combined into full_name (see NAME_PARTS). attendee_type is
     * the Internal / External column (migration 034).
     */
    public const FIELDS = ['full_name', 'first_name', 'middle_name', 'surname', 'company', 'attendee_type',
        'department', 'email', 'external_identifier'];
    /** Combined into Full Name in this order when no Full Name column is mapped. */
    public const NAME_PARTS = ['first_name', 'middle_name', 'surname'];
    /** Name parts each row must have when Full Name is built from parts. */
    private const REQUIRED_NAME_PARTS = ['first_name', 'surname'];
    public const ATTENDEE_TYPES = ['internal', 'external'];
    private const LABELS = [
        'full_name' => 'Full Name',
        'first_name' => 'First Name',
        'middle_name' => 'Middle Name',
        'surname' => 'Surname',
        'company' => 'Company',
        'attendee_type' => 'Internal/External',
        // Stored in attendees.department; shown as "Cluster" (location / region) since Phase 9.2.
        'department' => 'Cluster',
        'email' => 'Email',
        'external_identifier' => 'Guest No. / Employee ID / External Identifier',
    ];
    /** The combined Full Name is checked against full_name's limit (name parts have no limit of their own). */
    private const MAX_LENGTHS = ['full_name' => 200, 'company' => 200, 'department' => 150, 'email' => 190, 'external_identifier' => 190];

    /** Normalised header names that map directly to a field. */
    private const SYNONYMS = [
        'full_name' => ['fullname', 'name', 'employeename', 'completename', 'attendeename', 'participantname',
            'staffname', 'nameofemployee', 'employeefullname', 'nameofattendee', 'guestname'],
        'first_name' => ['firstname', 'givenname', 'fname', 'forename'],
        'middle_name' => ['middlename', 'middleinitial', 'mname'],
        'surname' => ['surname', 'lastname', 'familyname', 'lname'],
        'company' => ['company', 'companyname', 'name1', 'organization', 'organisation', 'organizationname',
            'employer', 'firm', 'agency', 'corporation', 'businessname', 'designationcompany', 'companydesignation'],
        'attendee_type' => ['internalexternal', 'internalorexternal', 'externalinternal', 'externalorinternal',
            'attendeetype', 'guesttype'],
        'department' => ['cluster', 'clustername', 'region', 'location', 'area', 'territory',
            'department', 'dept', 'departmentname', 'deptname', 'division', 'unit', 'section',
            'team', 'businessunit', 'group', 'office', 'departmentdivision'],
        'email' => ['email', 'emailaddress', 'mail', 'workemail', 'companyemail', 'officialemail', 'emailadd'],
        'external_identifier' => ['employeeid', 'employeeno', 'employeenumber', 'empid', 'empno', 'idno', 'idnumber',
            'id', 'staffid', 'staffno', 'badgeno', 'badgenumber', 'employeecode', 'empcode', 'personnelno',
            'externalid', 'externalidentifier', 'employeeidno', 'guestno', 'guestnumber', 'guestnum', 'guestid', 'guestidno'],
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

        return self::describe(SpreadsheetReader::read((string) $file['tmp_name'], $type), $filename, $type);
    }

    /**
     * The parse result for a sheet that was read: headers, rows, layout and the
     * suggested mapping, plus what was read (sheet, first/last spreadsheet row,
     * the workbook's other sheets that were NOT read) so the user can check
     * the row count against the file.
     *
     * Layouts: external_attendees (Cluster | Name 1 | Attendee 1..N),
     * guest_list (the standard six-column guest sheet) or standard.
     *
     * @param array{headers: list<string>, rows: list<array{rowNumber: int, cells: list<string>}>, sheet: ?string, otherSheets?: list<array{name: string, hidden: bool}>} $sheet
     * @return array<string, mixed>
     */
    public static function describe(array $sheet, string $filename, string $type): array
    {
        $read = [
            'sheet' => $sheet['sheet'],
            'otherSheets' => $sheet['otherSheets'] ?? [],
            'firstRowNumber' => $sheet['rows'][0]['rowNumber'] ?? null,
            'lastRowNumber' => $sheet['rows'] !== [] ? $sheet['rows'][count($sheet['rows']) - 1]['rowNumber'] : null,
        ];

        $external = self::expandExternalAttendees($sheet['headers'], $sheet['rows']);
        if ($external !== null) {
            return $read + [
                'filename' => $filename,
                'fileType' => $type,
                'layout' => 'external_attendees',
                'sourceRows' => count($sheet['rows']),
                'attendeeColumns' => $external['attendeeColumns'],
                'headers' => $external['headers'],
                'rows' => $external['rows'],
                'totalRows' => count($external['rows']),
                'suggestedMapping' => array_merge(array_fill_keys(self::FIELDS, null), ['full_name' => 0, 'company' => 1, 'department' => 2]),
            ];
        }

        $mapping = self::suggestMapping($sheet['headers']);

        return $read + [
            'filename' => $filename,
            'fileType' => $type,
            'layout' => self::isGuestList($mapping) ? 'guest_list' : 'standard',
            'headers' => $sheet['headers'],
            'rows' => $sheet['rows'],
            'totalRows' => count($sheet['rows']),
            'suggestedMapping' => $mapping,
        ];
    }

    /**
     * The standard guest sheet: Full Name comes from First Name + Surname
     * (no Full Name column) and there is no Email or Cluster column. The UI
     * then shows only the guest-sheet fields (no Email / Cluster / Full Name).
     *
     * @param array<string, int|null> $mapping
     */
    public static function isGuestList(array $mapping): bool
    {
        return $mapping['full_name'] === null && $mapping['first_name'] !== null && $mapping['surname'] !== null
            && $mapping['email'] === null && $mapping['department'] === null;
    }

    /** Headers of the downloadable guest-sheet template, in order. */
    public const GUEST_TEMPLATE_HEADERS = ['GUEST NO.', 'SURNAME', 'FIRST NAME', 'MIDDLE NAME', 'INTERNAL/EXTERNAL', 'DESIGNATION / COMPANY'];
    /** 0-based index of DESIGNATION / COMPANY in the template (highlighted red). */
    public const GUEST_TEMPLATE_COMPANY_COLUMN = 5;

    /**
     * Downloadable import template for the standard guest sheet: exactly the
     * six columns (no Email), DESIGNATION / COMPANY highlighted red (header
     * and data cells), GUEST NO. formatted as text so 001 keeps its zeros.
     * The first sheet holds only the header row (no sample person, so nothing
     * is imported by accident); the example is on the second sheet, which the
     * importer does not read.
     */
    public static function guestTemplate(): string
    {
        $options = [
            'highlightColumns' => [self::GUEST_TEMPLATE_COMPANY_COLUMN],
            'textColumns' => [0],
            'blankRows' => 500,
        ];

        return (new XlsxWriter())
            ->addSheet('Guests', self::GUEST_TEMPLATE_HEADERS, [], $options)
            ->addSheet('How to fill', self::GUEST_TEMPLATE_HEADERS, [
                ['1', 'Casiano', 'Gerry', '', 'EXTERNAL', 'AB Casiano Gravel & Sand'],
                [],
                ['One guest per row on the "Guests" sheet. Keep the header row and column order.'],
                ['GUEST NO.: optional, unique per event; leading zeros are kept (001).'],
                ['SURNAME and FIRST NAME: required. MIDDLE NAME: optional (may be blank).'],
                ['Full Name is built as FIRST NAME + MIDDLE NAME + SURNAME, e.g. "Gerry Casiano".'],
                ['INTERNAL/EXTERNAL: INTERNAL, EXTERNAL or blank.'],
                ['DESIGNATION / COMPANY (red column): the guest\'s company.'],
            ], ['highlightColumns' => [self::GUEST_TEMPLATE_COMPANY_COLUMN], 'textColumns' => [0]])
            ->toString();
    }

    /**
     * Client "External Attendees" layout (Phase 9.2):
     *   Cluster | Name 1 | Attendee 1 | Attendee 2 | ... | Attendee N
     * Cluster = location/region, Name 1 = COMPANY (never an attendee), every
     * non-empty "Attendee X" cell = one attendee who inherits the row's
     * Cluster and Company. Blank Attendee cells are skipped; Attendee 1 may be
     * blank while later ones are filled. Returns one row per attendee with
     * the columns Full Name, Company, Cluster (the original sheet row number
     * is kept), or null when the headers are not this layout.
     *
     * @param list<string> $headers
     * @param list<array{rowNumber: int, cells: list<string>}> $rows
     * @return array{headers: list<string>, rows: list<array{rowNumber: int, cells: list<string>}>, attendeeColumns: int}|null
     */
    public static function expandExternalAttendees(array $headers, array $rows): ?array
    {
        $cluster = $company = null;
        $attendeeColumns = []; // number => column index
        foreach ($headers as $index => $header) {
            $name = preg_replace('/[^a-z0-9]/', '', mb_strtolower($header)) ?? '';
            if ($name === 'cluster' && $cluster === null) {
                $cluster = $index;
            } elseif ($name === 'name1' && $company === null) {
                $company = $index;
            } elseif (preg_match('/^attendee(\d+)$/', $name, $m) && !isset($attendeeColumns[(int) $m[1]])) {
                $attendeeColumns[(int) $m[1]] = $index;
            }
        }
        // Needs "Name 1" plus at least one "Attendee X" column.
        if ($company === null || $attendeeColumns === []) {
            return null;
        }
        ksort($attendeeColumns);

        $expanded = [];
        foreach ($rows as $row) {
            $cells = $row['cells'];
            $companyName = SpreadsheetReader::cleanCell($cells[$company] ?? '');
            $clusterName = $cluster !== null ? SpreadsheetReader::cleanCell($cells[$cluster] ?? '') : '';
            foreach ($attendeeColumns as $index) {
                $person = SpreadsheetReader::cleanCell($cells[$index] ?? '');
                if ($person === '') {
                    continue;
                }
                $expanded[] = ['rowNumber' => $row['rowNumber'], 'cells' => [$person, $companyName, $clusterName]];
                if (count($expanded) > SpreadsheetReader::MAX_ROWS) {
                    throw HttpException::validation(['file' => ['The sheet lists more than ' . SpreadsheetReader::MAX_ROWS . ' attendees. Split it into smaller files.']]);
                }
            }
        }
        if ($expanded === []) {
            throw HttpException::validation(['file' => ['No attendee names were found in the Attendee columns.']]);
        }

        return ['headers' => ['Full Name', 'Company', 'Cluster'], 'rows' => $expanded, 'attendeeColumns' => count($attendeeColumns)];
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
            'company' => static fn (string $n): bool => str_contains($n, 'company') || str_contains($n, 'organi'),
            'department' => static fn (string $n): bool => str_contains($n, 'cluster') || str_contains($n, 'region')
                || str_contains($n, 'department') || str_contains($n, 'dept') || str_contains($n, 'division'),
            'external_identifier' => static fn (string $n): bool => (str_contains($n, 'employee') || str_contains($n, 'emp') || str_contains($n, 'staff'))
                && (str_ends_with($n, 'id') || str_ends_with($n, 'no') || str_contains($n, 'number') || str_contains($n, 'code')),
            // Never a name part: "SURNAME" alone is not a Full Name.
            'full_name' => static fn (string $n): bool => str_contains($n, 'name')
                && !preg_match('/dept|department|company|cluster|email|user|first|last|middle|nick|division|sur|given|family|^name\d+$/', $n),
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

        // Precedence: a Full Name column wins; name-part columns then stay
        // unmapped (kept as extra information, exactly as before).
        if ($mapping['full_name'] !== null) {
            foreach (self::NAME_PARTS as $part) {
                $mapping[$part] = null;
            }
        }

        return $mapping;
    }

    /**
     * FIRST + MIDDLE + SURNAME, trimmed, whitespace collapsed, empty parts
     * skipped (never "null", "undefined" or double spaces).
     *
     * @param array<string, string> $values
     */
    public static function combineName(array $values): string
    {
        $parts = [];
        foreach (self::NAME_PARTS as $part) {
            $value = trim(preg_replace('/\s+/u', ' ', (string) ($values[$part] ?? '')) ?? '');
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(' ', $parts);
    }

    /** 'internal' | 'external' for a recognised value (any case / spacing), '' for blank, null when invalid. */
    public static function normaliseAttendeeType(string $value): ?string
    {
        $value = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
        if ($value === '') {
            return '';
        }

        return in_array($value, self::ATTENDEE_TYPES, true) ? $value : null;
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
            $identity = self::identity($existing['full_name'], $existing['company'], $existing['department'], $existing['email'], $existing['external_identifier']);
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
        $nameFromParts = $mapping['full_name'] === null;
        // Header of the column each field comes from (for row-specific reasons).
        $columns = [];
        foreach (self::FIELDS as $field) {
            $columns[$field] = $mapping[$field] !== null ? ($headers[$mapping[$field]] ?? null) : null;
        }

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $values = [];
            foreach (self::FIELDS as $field) {
                $index = $mapping[$field];
                $values[$field] = $index !== null ? SpreadsheetReader::cleanCell($cells[$index] ?? '') : '';
            }
            if ($nameFromParts) {
                $values['full_name'] = self::combineName($values);
            }
            $type = self::normaliseAttendeeType($values['attendee_type']);

            $display = [
                'rowNumber' => $row['rowNumber'],
                'fullName' => $values['full_name'],
                'company' => $values['company'],
                // Normalised as "INTERNAL" / "EXTERNAL"; an invalid value is shown as written.
                'attendeeType' => $type !== null ? mb_strtoupper($type) : $values['attendee_type'],
                'department' => $values['department'],
                'email' => $values['email'],
                'externalIdentifier' => $values['external_identifier'],
            ];

            $errors = self::validateRow($values, $nameFromParts, $columns);
            if ($errors !== []) {
                $invalid[] = $display + ['reasons' => $errors];
                continue;
            }

            $identity = self::identity($values['full_name'], $values['company'], $values['department'], $values['email'], $values['external_identifier']);

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
                'company' => $values['company'] !== '' ? $values['company'] : null,
                'attendee_type' => $type !== null && $type !== '' ? $type : null,
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
                'company' => $r['company'],
                'attendeeType' => $r['attendee_type'] !== null ? mb_strtoupper($r['attendee_type']) : null,
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
    /**
     * Row-specific, actionable reasons. Each names the spreadsheet column it
     * is about; cell values are only echoed for Internal/External (a short
     * code), never names or email addresses.
     *
     * @param array<string, string> $values
     * @param array<string, string|null> $columns field => header of its column
     * @return list<string>
     */
    private static function validateRow(array $values, bool $nameFromParts, array $columns = []): array
    {
        $in = static fn (string $field): string => isset($columns[$field]) && $columns[$field] !== null
            ? ' (column "' . $columns[$field] . '")' : '';
        $errors = [];
        if ($nameFromParts) {
            foreach (self::REQUIRED_NAME_PARTS as $part) {
                if ($values[$part] === '') {
                    $errors[] = self::LABELS[$part] . ' is empty' . $in($part) . '. Fill it in; Full Name is built from First Name + Middle Name + Surname.';
                }
            }
        }
        if ($values['full_name'] === '' && $errors === []) {
            $errors[] = 'Full Name is empty' . $in('full_name') . '.';
        }
        if (self::normaliseAttendeeType($values['attendee_type']) === null) {
            $errors[] = 'Internal/External "' . mb_substr($values['attendee_type'], 0, 40) . '"' . $in('attendee_type')
                . ' is not valid. Use INTERNAL or EXTERNAL (or leave it blank).';
        }
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($values[$field]) > $max) {
                $label = $field === 'full_name' && $nameFromParts ? 'Full Name (First + Middle + Surname)' : self::LABELS[$field];
                $errors[] = $label . $in($field) . " may not be longer than {$max} characters (it has " . mb_strlen($values[$field]) . ').';
            }
        }
        if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $ats = substr_count($values['email'], '@');
            $errors[] = 'Email' . $in('email') . ' is not a valid email address: '
                . ($ats === 0 ? 'it has no "@".' : ($ats > 1 ? 'the cell holds more than one address. Keep one address per cell.' : 'check its spelling.'));
        }

        return $errors;
    }

    /** @return array{ext: string, email: string, name: string} */
    private static function identity(?string $name, ?string $company, ?string $department, ?string $email, ?string $ext): array
    {
        return [
            'ext' => self::normaliseText($ext),
            'email' => self::normaliseText($email),
            // Same name in a different company or cluster is a different person.
            'name' => self::normaliseText($name) . '|' . self::normaliseText($company) . '|' . self::normaliseText($department),
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
        // Precedence: when Full Name is mapped the name-part fields are ignored
        // (never combined a second time); their columns stay extra information.
        $fullNameMapped = ($mapping['full_name'] ?? null) !== null && ($mapping['full_name'] ?? null) !== '';
        foreach (self::FIELDS as $field) {
            $value = $mapping[$field] ?? null;
            if ($fullNameMapped && in_array($field, self::NAME_PARTS, true)) {
                $value = null;
            }
            if ($value === null || $value === '') {
                $cleanMapping[$field] = null;
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
        if (!$fullNameMapped) {
            $missing = array_values(array_filter(
                self::REQUIRED_NAME_PARTS,
                static fn (string $part): bool => !isset($errors[$part]) && ($cleanMapping[$part] ?? null) === null
            ));
            if (count($missing) === count(self::REQUIRED_NAME_PARTS)) {
                $errors['full_name'][] = 'Choose the column that contains Full Name, or the First Name and Surname columns.';
            } else {
                foreach ($missing as $part) {
                    $errors[$part][] = 'Choose the column that contains ' . self::LABELS[$part] . ', or choose a Full Name column.';
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
