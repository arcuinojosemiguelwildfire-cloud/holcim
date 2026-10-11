<?php

declare(strict_types=1);

/**
 * Attendee import test: the standard guest sheet
 *   GUEST NO. | SURNAME | FIRST NAME | MIDDLE NAME | INTERNAL/EXTERNAL | DESIGNATION / COMPANY
 * plus the existing Full Name and External Attendees formats.
 *
 * Part 1 (no database): header recognition, Full Name assembly, the
 * Internal/External rule and leading zeros in XLSX numbers.
 * Part 2 (HTTP, end to end): parse -> preview -> import on a throw-away
 * event, with validation, duplicate detection, precedence, preview = result,
 * attendee details, the Day Attendees report, and unchanged codes / QR tokens
 * of existing attendees.
 *
 *   HOLCIM_TEST_EMAIL=admin@example.com HOLCIM_TEST_PASSWORD='...' \
 *     php backend/tests/attendee-import-test.php http://localhost/Holcim/backend/api --write
 *
 * DEVELOPMENT DATABASE ONLY (same rules as phase8-smoke-test.php): it creates
 * its own "Import Test ..." event with fictional attendees. While it runs the
 * active event is set to completed; afterwards it is restored and the test
 * event archived. Refuses to run without --write or with APP_ENV=production.
 * Only fictional names are printed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/TestApiClient.php';

use App\Core\Database;
use App\Core\Env;
use App\Models\Attendee;
use App\Models\AttendeeQrCode;
use App\Services\AttendeeImportService as Import;
use App\Utils\SpreadsheetReader;
use App\Utils\Token;
use App\Utils\XlsxWriter;

$passed = 0;
$failed = 0;
function check(string $label, bool $condition, mixed $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}" . ($detail !== '' ? '  -> ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
    }
}
function section(string $title): void
{
    echo "\n{$title}\n";
}

/** Mapping with every field present (null = not mapped). */
function mapping(array $set): array
{
    return array_merge(array_fill_keys(Import::FIELDS, null), $set);
}

/**
 * XLSX whose "__N<value>S<style>__" cells become NUMBER cells with that style;
 * style 2 has the number format "000" (Excel shows 7 as 007).
 */
function xlsxWithNumbers(array $headers, array $rows): string
{
    $file = tempnam(sys_get_temp_dir(), 'ait-') . '.xlsx';
    file_put_contents($file, (new XlsxWriter())->addSheet('Guests', $headers, $rows)->toString());
    $zip = new ZipArchive();
    $zip->open($file);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $sheet = preg_replace_callback(
        '/<c r="([A-Z]+\d+)" t="inlineStr"><is><t xml:space="preserve">__N(\d+)S(\d)__<\/t><\/is><\/c>/',
        static fn (array $m): string => '<c r="' . $m[1] . '"' . ($m[3] !== '0' ? ' s="' . $m[3] . '"' : '') . '><v>' . $m[2] . '</v></c>',
        $sheet
    );
    $zip->addFromString('xl/worksheets/sheet1.xml', (string) $sheet);
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="000"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '</styleSheet>');
    $zip->close();

    return $file;
}

/**
 * Workbook with several sheets; sheets named in $hidden get state="hidden"
 * (like a Google Sheets export with hidden working tabs).
 *
 * @param list<array{0: string, 1: list<string>, 2: list<list<string>>, 3?: array<string, mixed>}> $sheets
 */
function workbook(array $sheets, array $hidden = []): string
{
    $writer = new XlsxWriter();
    foreach ($sheets as $sheet) {
        $writer->addSheet($sheet[0], $sheet[1], $sheet[2], $sheet[3] ?? []);
    }
    $file = tempnam(sys_get_temp_dir(), 'ait-') . '.xlsx';
    file_put_contents($file, $writer->toString());
    $zip = new ZipArchive();
    $zip->open($file);
    $xml = (string) $zip->getFromName('xl/workbook.xml');
    foreach ($hidden as $name) {
        $xml = str_replace('<sheet name="' . $name . '"', '<sheet state="hidden" name="' . $name . '"', $xml);
    }
    $zip->addFromString('xl/workbook.xml', $xml);
    $zip->close();

    return $file;
}

/** The 361-guest list: G001..G361-style guest numbers (zero-padded), blank middle names, mixed types. */
function guestRows(string $prefix, int $count): array
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [$prefix . str_pad((string) $i, 3, '0', STR_PAD_LEFT), "Surname{$i}", "First{$i}", $i % 9 === 0 ? 'M.' : '',
            $i % 3 === 0 ? 'INTERNAL' : 'EXTERNAL', 'Company ' . ($i % 40)];
    }

    return $rows;
}

const SIX = ['GUEST NO.', 'SURNAME', 'FIRST NAME', 'MIDDLE NAME', 'INTERNAL/EXTERNAL', 'DESIGNATION / COMPANY'];

// ======================================================================== Part 1
section('Header recognition (no database)');
$m = Import::suggestMapping(SIX);
check('Exact six columns: GUEST NO.->external_identifier, SURNAME/FIRST/MIDDLE->name parts, INTERNAL/EXTERNAL->attendee_type, DESIGNATION / COMPANY->company',
    $m === mapping(['external_identifier' => 0, 'surname' => 1, 'first_name' => 2, 'middle_name' => 3, 'attendee_type' => 4, 'company' => 5]), $m);
check('SURNAME is never mapped to Full Name', $m['full_name'] === null);
check('INTERNAL/EXTERNAL is not mapped to Cluster or Company', $m['department'] === null && $m['company'] === 5);
$m = Import::suggestMapping(['guest  no', 'Last Name', 'Given Name', 'Middle Initial', 'Internal / External', 'Designation/Company']);
check('Spacing / punctuation / case variants and aliases (Last Name, Given Name, Middle Initial)',
    $m === mapping(['external_identifier' => 0, 'surname' => 1, 'first_name' => 2, 'middle_name' => 3, 'attendee_type' => 4, 'company' => 5]), $m);
$m = Import::suggestMapping(['Guest Number', 'surname', 'first name']);
check('"Guest Number" -> external_identifier; middle name optional', $m === mapping(['external_identifier' => 0, 'surname' => 1, 'first_name' => 2]), $m);
$m = Import::suggestMapping(['Full Name', 'Company', 'Cluster', 'Email', 'Employee ID']);
check('Legacy Full Name headers map exactly as before', $m === mapping(['full_name' => 0, 'company' => 1, 'department' => 2, 'email' => 3, 'external_identifier' => 4]), $m);
$m = Import::suggestMapping(['Employee Name', 'Dept', 'Emp No']);
check('Legacy keyword matches still work (Employee Name, Dept, Emp No)', $m === mapping(['full_name' => 0, 'department' => 1, 'external_identifier' => 2]), $m);
$m = Import::suggestMapping(['Name', 'First Name', 'Middle Name', 'Surname']);
check('Precedence: a Full Name column wins; name-part columns stay unmapped (extra information)', $m === mapping(['full_name' => 0]), $m);
$m = Import::suggestMapping(['Surname']);
check('A lone SURNAME column is a Surname, not a Full Name', $m === mapping(['surname' => 0]), $m);

section('Full Name assembly and Internal/External (no database)');
$name = static fn (string $f, string $mid, string $s): string => Import::combineName(['first_name' => $f, 'middle_name' => $mid, 'surname' => $s]);
check('FIRST + MIDDLE + SURNAME = "Gerry sample Casiano"', $name('Gerry', 'sample', 'Casiano') === 'Gerry sample Casiano');
check('Blank MIDDLE NAME: "Gerry" + "" + "Casiano" = "Gerry Casiano"', $name('Gerry', '', 'Casiano') === 'Gerry Casiano');
check('Missing middle name -> "Maria Santos" (single space)', $name('Maria', '', 'Santos') === 'Maria Santos');
check('Extra whitespace / blank parts trimmed and collapsed', $name("  Jose \t  Mari ", '   ', ' Reyes  ') === 'Jose Mari Reyes');
check('Only blanks -> empty string (never "null" / "undefined")', $name('', ' ', '') === '' && !str_contains($name('A', '', 'B'), 'null'));
check('Missing parts in the array are treated as empty', Import::combineName(['surname' => 'Uy']) === 'Uy');
check('INTERNAL / " external " / "External" normalised', Import::normaliseAttendeeType('INTERNAL') === 'internal'
    && Import::normaliseAttendeeType(' external ') === 'external' && Import::normaliseAttendeeType('External') === 'external');
check('Blank Internal/External allowed; other values invalid', Import::normaliseAttendeeType('  ') === ''
    && Import::normaliseAttendeeType('VISITOR') === null && Import::normaliseAttendeeType('int') === null);

section('Leading zeros (no database)');
$file = xlsxWithNumbers(['GUEST NO.', 'SURNAME', 'FIRST NAME'], [
    ['__N7S2__', 'Padded', 'Seven'],
    ['__N8S0__', 'Plain', 'Eight'],
    ['0009', 'Text', 'Nine'],
]);
$read = SpreadsheetReader::read($file, 'xlsx');
@unlink($file);
$ids = array_map(static fn (array $r): string => $r['cells'][0], $read['rows']);
check('XLSX number formatted "000" -> "007"; plain number 8 -> "8"; text "0009" kept', $ids === ['007', '8', '0009'], $ids);

section('Sheet selection and row counting (reproduces the 128-row read)');
// Same structure as the reported workbook: a HIDDEN first tab with an old
// 128-row customer list (Account Name / Email address) and the visible
// "FINAL ATTENDEES" tab with the 361 guests, followed by ~640 formatted but
// empty rows (as Google Sheets exports them) and a blank row in the middle.
$decoy = [];
for ($i = 1; $i <= 128; $i++) {
    $decoy[] = ['North', 'Std', "Account {$i}", '', $i % 4 === 0 ? 'a@x.invalid / b@x.invalid' : "acct{$i}@x.invalid"];
}
$guests = guestRows('', 361);
array_splice($guests, 200, 0, [['', '', '', '', '', '']]); // a blank row between row 201 and 202
$file = workbook([
    ['External Customers', ['Cluster', 'Bed Type', 'Account Name', 'Award', 'Email address'], $decoy],
    ['FINAL ATTENDEES', SIX, $guests, ['highlightColumns' => [5], 'blankRows' => 640]],
    ['Customer Seatplan', ['Table', 'Seat'], [['1', 'A']]],
], ['External Customers', 'Customer Seatplan']);
$read = SpreadsheetReader::read($file, 'xlsx');
$described = Import::describe($read, 'final.xlsx', 'xlsx');
check('Hidden first sheet skipped: the visible "FINAL ATTENDEES" sheet is read', $read['sheet'] === 'FINAL ATTENDEES', $read['sheet']);
check('All 361 data rows read (blank and formatted-empty rows not counted, nothing cut off)', count($read['rows']) === 361
    && $described['firstRowNumber'] === 2 && $described['lastRowNumber'] === 363, [count($read['rows']), $described['firstRowNumber'], $described['lastRowNumber']]);
check('The other sheets are reported (both hidden), not silently ignored', $described['otherSheets'] === [
    ['name' => 'External Customers', 'hidden' => true], ['name' => 'Customer Seatplan', 'hidden' => true]], $described['otherSheets']);
check('Six-column sheet is the dedicated guest_list layout, no Email / Cluster / Full Name mapped', $described['layout'] === 'guest_list'
    && $described['suggestedMapping'] === mapping(['external_identifier' => 0, 'surname' => 1, 'first_name' => 2, 'middle_name' => 3, 'attendee_type' => 4, 'company' => 5]), $described['suggestedMapping']);
@unlink($file);
$file = workbook([['Only Hidden', SIX, guestRows('', 3)]], ['Only Hidden']);
check('Workbook whose only sheet is hidden: that sheet is still read', SpreadsheetReader::read($file, 'xlsx')['sheet'] === 'Only Hidden');
@unlink($file);
$file = workbook([['Notes', ['x'], [['y']]], ['External Attendees', ['Cluster', 'Name 1', 'Attendee 1'], [['N', 'Co', 'P']]]], ['External Attendees']);
check('A sheet named "External Attendees" is still preferred (existing rule)', SpreadsheetReader::read($file, 'xlsx')['sheet'] === 'External Attendees');
@unlink($file);
$csvFile = tempnam(sys_get_temp_dir(), 'ait-') . '.csv';
file_put_contents($csvFile, implode("\n", [implode(',', SIX), ...array_map(static fn ($r) => implode(',', $r), guestRows('', 5)), ',,,,,', '', ',,,,,']) . "\n");
check('CSV: separator-only and empty lines are skipped (5 rows)', count(SpreadsheetReader::read($csvFile, 'csv')['rows']) === 5);
@unlink($csvFile);

section('Guest template (no Email, Company column red)');
$template = Import::guestTemplate();
$file = tempnam(sys_get_temp_dir(), 'ait-') . '.xlsx';
file_put_contents($file, $template);
$zip = new ZipArchive();
check('Template is a valid XLSX (zip opens, 2 sheets)', $zip->open($file) === true && $zip->getFromName('xl/worksheets/sheet2.xml') !== false);
$sheet1 = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
$styles = simplexml_load_string((string) $zip->getFromName('xl/styles.xml'));
$wb = (string) $zip->getFromName('xl/workbook.xml');
$zip->close();
$tRead = SpreadsheetReader::read($file, 'xlsx');
$tDescribed = Import::describe($tRead, 'template.xlsx', 'xlsx');
check('Template headers are exactly the six columns, in order', $tRead['headers'] === SIX && $tRead['sheet'] === 'Guests', $tRead['headers']);
check('No Email column anywhere in the template', !preg_grep('/mail/i', $tRead['headers']) && !str_contains(strtolower((string) $sheet1->asXML()), 'email'));
check('Template has no data rows on the Guests sheet (nothing imported by accident) and is the guest_list layout', $tRead['rows'] === [] && $tDescribed['layout'] === 'guest_list');
$xfs = [];
foreach ($styles->cellXfs->xf as $xf) {
    $xfs[] = $xf;
}
$fills = [];
foreach ($styles->fills->fill as $fill) {
    $fills[] = (string) ($fill->patternFill->fgColor['rgb'] ?? '');
}
$fillOfStyle = static fn (int $s): string => $fills[(int) ($xfs[$s]['fillId'] ?? 0)] ?? '';
$cellStyle = [];
foreach ($sheet1->sheetData->row as $row) {
    foreach ($row->c as $c) {
        $cellStyle[(string) $c['r']] = (int) $c['s'];
    }
}
check('DESIGNATION / COMPANY header cell (F1) has a red fill', $fillOfStyle($cellStyle['F1'] ?? 0) === XlsxWriter::HIGHLIGHT_HEADER_FILL, $cellStyle['F1'] ?? null);
check('Company data-entry cells (F2..F501) have the light-red fill', $fillOfStyle($cellStyle['F2'] ?? 0) === XlsxWriter::HIGHLIGHT_FILL
    && $fillOfStyle($cellStyle['F501'] ?? 0) === XlsxWriter::HIGHLIGHT_FILL, [$cellStyle['F2'] ?? null, $cellStyle['F501'] ?? null]);
$colStyle = [];
foreach ($sheet1->cols->col as $col) {
    $colStyle[(int) $col['min']] = (int) $col['style'];
}
check('Whole column F styled red (rows typed later stay red); no other column filled', $fillOfStyle($colStyle[6] ?? 0) === XlsxWriter::HIGHLIGHT_FILL
    && array_filter([1, 2, 3, 4, 5], static fn (int $c) => $fillOfStyle($colStyle[$c] ?? 0) !== '') === []
    && array_filter(['A1', 'B1', 'C1', 'D1', 'E1'], static fn (string $r) => $fillOfStyle($cellStyle[$r] ?? 0) !== '') === [], $colStyle);
check('GUEST NO. column is formatted as Text (keeps 001)', (int) ($xfs[$colStyle[1] ?? 0]['numFmtId'] ?? 0) === 49);
check('The example lives on a second "How to fill" sheet, which is not the sheet that is read', str_contains($wb, 'How to fill') && $tRead['sheet'] === 'Guests');
@unlink($file);

// ======================================================================== Part 2
$positional = array_values(array_filter(array_slice($argv, 1), static fn ($a) => !str_starts_with($a, '--')));
$baseUrl = rtrim($positional[0] ?? 'http://localhost/Holcim/backend/api', '/');
$adminEmail = getenv('HOLCIM_TEST_EMAIL') ?: '';
$adminPassword = getenv('HOLCIM_TEST_PASSWORD') ?: '';
if (!in_array('--write', $argv, true)) {
    echo "\nPart 2 (HTTP) skipped: re-run with --write on a DEVELOPMENT database.\n\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
if (Env::get('APP_ENV') === 'production') {
    fwrite(STDERR, "Refusing to run with APP_ENV=production.\n");
    exit(1);
}
if (!function_exists('curl_init') || $adminEmail === '' || $adminPassword === '') {
    fwrite(STDERR, "Part 2 needs the curl extension and HOLCIM_TEST_EMAIL / HOLCIM_TEST_PASSWORD (an admin account).\n");
    exit(1);
}

$pdo = Database::connection();
$stamp = date('His') . '-' . bin2hex(random_bytes(2));
$admin = new TestApiClient($baseUrl);
if ($admin->login($adminEmail, $adminPassword) !== 200) {
    fwrite(STDERR, "Admin login failed.\n");
    exit(1);
}
$originalActive = $pdo->query("SELECT id FROM events WHERE status = 'active' LIMIT 1")->fetchColumn();
$originalActive = $originalActive !== false ? (int) $originalActive : null;
$eventId = null;

$upload = static function (string $file, string $filename) use ($admin): array {
    $raw = $admin->upload('/attendees/import/parse', $file, $filename);
    @unlink($file);

    return json_decode($raw, true)['data'] ?? ['raw' => $raw];
};
$xlsx = static function (array $headers, array $rows): string {
    $file = tempnam(sys_get_temp_dir(), 'ait-') . '.xlsx';
    file_put_contents($file, (new XlsxWriter())->addSheet('Guests', $headers, $rows)->toString());

    return $file;
};
$csv = static function (array $headers, array $rows): string {
    $file = tempnam(sys_get_temp_dir(), 'ait-') . '.csv';
    $h = fopen($file, 'wb');
    foreach ([$headers, ...$rows] as $line) {
        fputcsv($h, $line, ',', '"', '');
    }
    fclose($h);

    return $file;
};
$payload = static fn (array $parsed, ?array $map = null, string $name = 'guests.xlsx'): array =>
    ['filename' => $name, 'headers' => $parsed['headers'] ?? [], 'rows' => $parsed['rows'] ?? [], 'mapping' => $map ?? ($parsed['suggestedMapping'] ?? [])];
$byRow = static function (array $list): array {
    $out = [];
    foreach ($list as $item) {
        $out[$item['rowNumber']] = $item;
    }

    return $out;
};
$snapshot = static function (int $eventId) use ($pdo): array {
    return $pdo->query("SELECT a.id, a.attendee_code, a.full_name, a.company, a.attendee_type, a.external_identifier, a.extra_data, a.updated_at, q.token
                        FROM attendees a LEFT JOIN attendee_qr_codes q ON q.attendee_id = a.id WHERE a.event_id = {$eventId} ORDER BY a.id")->fetchAll();
};

try {
    section('Setup (throw-away event)');
    if ($originalActive !== null) {
        $admin->request('PATCH', "/events/{$originalActive}/status", ['status' => 'completed']);
    }
    $created = $admin->request('POST', '/events', ['name' => "Import Test {$stamp}", 'event_date' => '2026-10-20', 'status' => 'draft', 'description' => '']);
    $eventId = (int) ($created['body']['data']['event']['id'] ?? 0);
    check('Admin creates and activates the test event', $created['status'] === 201
        && $admin->request('PATCH', "/events/{$eventId}/status", ['status' => 'active'])['status'] === 200, $created['raw']);
    $dayId = (int) (($admin->data('GET', "/events/{$eventId}/days")['days'] ?? [])[0]['id'] ?? 0);

    // Existing attendees (with QR tokens) - the import must never change them.
    $existing = [
        Attendee::create($eventId, 'ATT-0001', ['full_name' => 'Ken Tan', 'company' => 'Delta', 'external_identifier' => '500'], null),
        Attendee::create($eventId, 'ATT-0002', ['full_name' => 'Rosa Casiano', 'company' => 'AB Casiano Gravel & Sand'], null),
    ];
    foreach ($existing as $id) {
        AttendeeQrCode::create($id, Token::random(24));
    }
    $before = $snapshot($eventId);

    // -------------------------------------------------------- the six-column sheet
    section('Six-column guest sheet: preview');
    $rows = [
        /* r2  */ ['1', 'Casiano', 'Gerry', 'sample', 'EXTERNAL', 'AB Casiano Gravel & Sand'],
        /* r3  */ ['007', 'Santos', 'Maria', '', 'internal', 'Acme'],
        /* r4  */ ['008', '  Reyes  ', "  Jose   Mari ", '   ', ' External ', ' Beta  Corp '],
        /* r5  */ ['009', 'Cruz', '', 'X', 'EXTERNAL', 'Co'],
        /* r6  */ ['010', '', 'Ana', '', 'EXTERNAL', 'Co'],
        /* r7  */ ['011', 'Dela Cruz', 'Pedro', '', 'VISITOR', 'Co'],
        /* r8  */ ['1', 'Lim', 'Carlo', '', 'EXTERNAL', 'Gamma'],
        /* r9  */ ['012', 'Casiano', 'Linda', '', 'EXTERNAL', 'AB Casiano Gravel & Sand'],
        /* r10 */ ['500', 'Tan', 'Kenneth', '', 'INTERNAL', 'Delta'],
        /* r11 */ ['', 'Ong', 'Lea', '', '', 'Omega'],
        /* r12 */ ['', 'Ong', 'Lea', '', '', 'Omega'],
        /* r13 */ ['07', 'Uy', 'Ben', '', 'INTERNAL', 'Acme'],
        /* r14 */ ['', '', '', '', '', ''],
    ];
    $parsed = $upload($xlsx(SIX, $rows), 'guests.xlsx');
    check('Parse: guest_list layout, headers kept, mapping suggested from the headers', ($parsed['layout'] ?? '') === 'guest_list'
        && ($parsed['headers'] ?? []) === SIX
        && ($parsed['suggestedMapping'] ?? []) === mapping(['external_identifier' => 0, 'surname' => 1, 'first_name' => 2, 'middle_name' => 3, 'attendee_type' => 4, 'company' => 5]), $parsed);
    check('Blank spreadsheet rows are skipped (12 data rows)', ($parsed['totalRows'] ?? 0) === 12, $parsed['totalRows'] ?? null);
    $guestPayload = $payload($parsed);
    $preview = $admin->data('POST', '/attendees/import/preview', $guestPayload);
    $new = $byRow($preview['newPreview'] ?? []);
    $bad = $byRow($preview['invalid'] ?? []);
    $dup = $byRow($preview['duplicates'] ?? []);
    check('Summary: 12 rows, 6 new, 3 invalid, 3 duplicates (1 existing, 2 in file)', ($preview['summary'] ?? null)
        === ['total' => 12, 'valid' => 6, 'invalid' => 3, 'duplicates' => 3, 'duplicatesExisting' => 1, 'duplicatesInFile' => 2], $preview['summary'] ?? $preview);
    check('Sample row: Guest No. 1 / Gerry sample Casiano / EXTERNAL / AB Casiano Gravel & Sand', ($new[2] ?? null) === [
        'rowNumber' => 2, 'fullName' => 'Gerry sample Casiano', 'company' => 'AB Casiano Gravel & Sand', 'attendeeType' => 'EXTERNAL',
        'department' => null, 'email' => null, 'externalIdentifier' => '1'], $new[2] ?? null);
    check('Missing middle name: "Maria Santos", INTERNAL, Guest No. 007 (leading zeros kept)', ($new[3]['fullName'] ?? '') === 'Maria Santos'
        && ($new[3]['attendeeType'] ?? '') === 'INTERNAL' && ($new[3]['externalIdentifier'] ?? '') === '007', $new[3] ?? null);
    check('Extra whitespace: "Jose Mari Reyes", company "Beta Corp", type EXTERNAL', ($new[4]['fullName'] ?? '') === 'Jose Mari Reyes'
        && ($new[4]['company'] ?? '') === 'Beta Corp' && ($new[4]['attendeeType'] ?? '') === 'EXTERNAL', $new[4] ?? null);
    check('Missing first name -> invalid, reason names the column', ($bad[5]['reasons'] ?? [])
        === ['First Name is empty (column "FIRST NAME"). Fill it in; Full Name is built from First Name + Middle Name + Surname.'] && ($bad[5]['fullName'] ?? '') === 'X Cruz', $bad[5] ?? null);
    check('Missing surname -> invalid, reason names the column', ($bad[6]['reasons'] ?? [])
        === ['Surname is empty (column "SURNAME"). Fill it in; Full Name is built from First Name + Middle Name + Surname.'], $bad[6] ?? null);
    check('Invalid Internal/External "VISITOR" -> invalid, value shown as written', str_contains(implode(' ', $bad[7]['reasons'] ?? []), 'Internal/External "VISITOR" (column "INTERNAL/EXTERNAL") is not valid')
        && ($bad[7]['attendeeType'] ?? '') === 'VISITOR' && ($bad[7]['externalIdentifier'] ?? '') === '011', $bad[7] ?? null);
    check('Duplicate Guest No. within the file: row 8 matches row 2 by Guest No.', ($dup[8]['matchedBy'] ?? '') === 'external_identifier'
        && ($dup[8]['matches'] ?? null) === ['type' => 'file', 'rowNumber' => 2, 'fullName' => 'Gerry sample Casiano'] && ($dup[8]['externalIdentifier'] ?? '') === '1', $dup[8] ?? null);
    check('Guest No. already used by an existing attendee: row 10 matches ATT-0001', ($dup[10]['matchedBy'] ?? '') === 'external_identifier'
        && ($dup[10]['matches']['type'] ?? '') === 'existing' && ($dup[10]['matches']['attendeeCode'] ?? '') === 'ATT-0001', $dup[10] ?? null);
    check('Same surname + company, different person (Linda Casiano) is NEW', isset($new[9]) && ($new[9]['fullName'] ?? '') === 'Linda Casiano');
    check('Blank Guest No. allowed (optional); blank Internal/External allowed', array_key_exists('externalIdentifier', $new[11] ?? []) && $new[11]['externalIdentifier'] === null && $new[11]['attendeeType'] === null, $new[11] ?? null);
    check('No Guest No.: legacy name + company rule still finds the repeat (row 12 = row 11)', ($dup[12]['matchedBy'] ?? '') === 'name_department'
        && ($dup[12]['matches']['rowNumber'] ?? 0) === 11, $dup[12] ?? null);
    check('"07" and "007" are different Guest Nos.', isset($new[13]) && ($new[13]['externalIdentifier'] ?? '') === '07');

    section('Six-column guest sheet: import');
    $r = $admin->request('POST', '/attendees/import', $guestPayload);
    $result = $r['body']['data'] ?? [];
    check('Import: 201, imported = preview valid (6), summary identical to the preview', $r['status'] === 201 && ($result['imported'] ?? null) === 6
        && ($result['summary'] ?? null) === ($preview['summary'] ?? 0), $r['raw']);
    $createdRows = $byRow($result['created'] ?? []);
    check('Created rows are exactly the preview\'s new rows', array_keys($createdRows) === array_keys($new), array_keys($createdRows));
    check('Attendee codes are generated (ATT-0003...), never the Guest No.', ($createdRows[2]['attendeeCode'] ?? '') === 'ATT-0003'
        && !array_filter($createdRows, static fn ($c) => !preg_match('/^ATT-\d{4}$/', $c['attendeeCode'])), $createdRows);
    $gerry = $pdo->query("SELECT * FROM attendees WHERE event_id = {$eventId} AND external_identifier = '1'")->fetch();
    check('Stored: full_name "Gerry sample Casiano", company, attendee_type external, external_identifier "1"', ($gerry['full_name'] ?? '') === 'Gerry sample Casiano'
        && $gerry['company'] === 'AB Casiano Gravel & Sand' && $gerry['attendee_type'] === 'external' && $gerry['department'] === null, $gerry);
    check('Mapped name-part / type / Guest No. columns are not duplicated in extra_data', $gerry['extra_data'] === null, $gerry['extra_data'] ?? null);
    check('"007" stored with its leading zeros', ($pdo->query("SELECT full_name FROM attendees WHERE event_id = {$eventId} AND external_identifier = '007'")->fetchColumn()) === 'Maria Santos');
    check('No QR token is created or used for the Guest No.', (int) $pdo->query("SELECT COUNT(*) FROM attendee_qr_codes q JOIN attendees a ON a.id = q.attendee_id
        WHERE a.event_id = {$eventId} AND (q.token IN ('1', '007', '07', '012') OR a.import_batch_id IS NOT NULL)")->fetchColumn() === 0);
    $detail = $admin->data('GET', '/attendees/' . (int) $gerry['id'])['attendee'] ?? [];
    check('Attendee details return attendeeType "external" and Guest No. as externalIdentifier', ($detail['attendeeType'] ?? '') === 'external'
        && ($detail['externalIdentifier'] ?? '') === '1' && ($detail['extraData'] ?? null) === [], $detail);
    $again = $admin->data('POST', '/attendees/import/preview', $guestPayload);
    check('Re-importing the same file: every valid row is now a duplicate of an existing attendee', ($again['summary']['valid'] ?? -1) === 0
        && ($again['summary']['duplicatesExisting'] ?? 0) === 9, $again['summary'] ?? null);

    // -------------------------------------------------------- precedence + mapping rules
    section('Full Name + name parts in one file (precedence)');
    $prec = $upload($csv(['Full Name', 'FIRST NAME', 'MIDDLE NAME', 'SURNAME', 'GUEST NO.'], [['Paolo M. Garcia', 'Paolo', 'Mendoza', 'Garcia', 'P-1']]), 'prec.csv');
    check('Suggested: Full Name mapped, name parts left unmapped', ($prec['suggestedMapping'] ?? []) === mapping(['full_name' => 0, 'external_identifier' => 4]), $prec['suggestedMapping'] ?? $prec);
    $forced = mapping(['full_name' => 0, 'first_name' => 1, 'middle_name' => 2, 'surname' => 3, 'external_identifier' => 4]);
    $p = $admin->data('POST', '/attendees/import/preview', $payload($prec, $forced, 'prec.csv'));
    check('Both mapped: the Full Name column wins, the name is not combined twice', ($p['newPreview'][0]['fullName'] ?? '') === 'Paolo M. Garcia', $p);
    $r = $admin->request('POST', '/attendees/import', $payload($prec, $forced, 'prec.csv'));
    $extra = json_decode((string) $pdo->query("SELECT extra_data FROM attendees WHERE event_id = {$eventId} AND external_identifier = 'P-1'")->fetchColumn(), true);
    check('...and the ignored name-part columns are kept as extra information', $r['status'] === 201
        && $extra === ['FIRST NAME' => 'Paolo', 'MIDDLE NAME' => 'Mendoza', 'SURNAME' => 'Garcia'], $extra);

    $r = $admin->request('POST', '/attendees/import/preview', $payload($parsed, mapping(['surname' => 1, 'external_identifier' => 0])));
    check('Surname mapped without First Name and no Full Name -> 422 on first_name', $r['status'] === 422
        && isset($r['body']['error']['details']['fields']['first_name']) && !isset($r['body']['error']['details']['fields']['full_name']), $r['raw']);
    $r = $admin->request('POST', '/attendees/import/preview', $payload($parsed, mapping(['company' => 5])));
    check('No Full Name and no name parts -> 422 on full_name', $r['status'] === 422 && isset($r['body']['error']['details']['fields']['full_name']), $r['raw']);
    $r = $admin->request('POST', '/attendees/import/preview', $payload($parsed, mapping(['first_name' => 2, 'surname' => 2])));
    check('Same column for two fields -> 422', $r['status'] === 422, $r['raw']);
    $long = str_repeat('A', 120);
    $p = $admin->data('POST', '/attendees/import/preview', $payload(['headers' => SIX, 'rows' => [['rowNumber' => 2, 'cells' => ['900', $long, $long, '', '', '']]]], $guestPayload['mapping']));
    check('Combined Full Name over 200 characters -> invalid (existing limit)', in_array('Full Name (First + Middle + Surname) may not be longer than 200 characters (it has 241).', $p['invalid'][0]['reasons'] ?? [], true), $p);

    // -------------------------------------------------------- legacy formats
    section('Existing formats');
    $legacy = $upload($csv(['Full Name', 'Company', 'Cluster', 'Email', 'Employee ID', 'Shirt Size'], [
        ['Legacy Person One', 'Legacy Co', 'North', 'legacy1@example.invalid', 'EMP-1', 'L'],
        ['Legacy Person Two', 'Legacy Co', 'North', '', '', 'M'],
    ]), 'legacy.csv');
    check('Full Name-only CSV: same mapping as before', ($legacy['suggestedMapping'] ?? []) === mapping(['full_name' => 0, 'company' => 1, 'department' => 2, 'email' => 3, 'external_identifier' => 4]), $legacy);
    $lp = $admin->data('POST', '/attendees/import/preview', $payload($legacy, null, 'legacy.csv'));
    $r = $admin->request('POST', '/attendees/import', $payload($legacy, null, 'legacy.csv'));
    $row = $pdo->query("SELECT full_name, company, department, email, external_identifier, attendee_type, extra_data FROM attendees WHERE event_id = {$eventId} AND full_name = 'Legacy Person One'")->fetch();
    check('Full Name-only import: 2 imported (= preview), fields stored, type NULL, unmapped column in extra_data', $r['status'] === 201
        && ($r['body']['data']['imported'] ?? 0) === 2 && ($lp['summary']['valid'] ?? 0) === 2 && $row['company'] === 'Legacy Co' && $row['department'] === 'North'
        && $row['email'] === 'legacy1@example.invalid' && $row['external_identifier'] === 'EMP-1' && $row['attendee_type'] === null
        && json_decode((string) $row['extra_data'], true) === ['Shirt Size' => 'L'], [$r['raw'], $row]);
    // Old clients that send only the five original mapping keys keep working.
    $oldMapping = ['full_name' => 0, 'company' => 1, 'department' => 2, 'email' => 3, 'external_identifier' => 4];
    $op = $admin->data('POST', '/attendees/import/preview', ['filename' => 'legacy.csv', 'headers' => $legacy['headers'], 'rows' => $legacy['rows'], 'mapping' => $oldMapping]);
    check('A mapping with only the original five keys is accepted (both rows now duplicates)', ($op['summary']['duplicatesExisting'] ?? 0) === 2, $op);

    $book = (new XlsxWriter())->addSheet('External Attendees', ['Cluster', 'Name 1', 'Attendee 1', 'Attendee 2'], [
        ['Visayas', "Ext Co {$stamp}", 'Ext Person A', 'Ext Person B'],
        ['Mindanao', "Ext Co {$stamp}", '', 'Ext Person A'],
    ]);
    $file = tempnam(sys_get_temp_dir(), 'ait-') . '.xlsx';
    file_put_contents($file, $book->toString());
    $ext = $upload($file, 'external.xlsx');
    check('External Attendees layout still detected and expanded (3 attendees)', ($ext['layout'] ?? '') === 'external_attendees' && ($ext['totalRows'] ?? 0) === 3
        && ($ext['suggestedMapping'] ?? []) === mapping(['full_name' => 0, 'company' => 1, 'department' => 2]), $ext);
    $ep = $admin->data('POST', '/attendees/import/preview', $payload($ext, null, 'external.xlsx'));
    $r = $admin->request('POST', '/attendees/import', $payload($ext, null, 'external.xlsx'));
    check('External Attendees import: 3 new (same name in two clusters = two people), result = preview', ($ep['summary']['valid'] ?? 0) === 3
        && ($r['body']['data']['imported'] ?? 0) === 3 && ($r['body']['data']['summary'] ?? null) === ($ep['summary'] ?? 0), $r['raw']);

    // -------------------------------------------------------- 361-row workbook
    section('361-guest workbook with a hidden first sheet (end to end)');
    $r = $admin->request('GET', '/attendees/import/template.xlsx');
    check('GET /attendees/import/template.xlsx returns the XLSX template', $r['status'] === 200 && str_starts_with($r['raw'], "PK") && strlen($r['raw']) > 1000, $r['status']);
    $big = guestRows('G', 361);
    $big[49][2] = '';            // spreadsheet row 51: no first name -> invalid
    $big[119][4] = 'GUEST';      // row 121: invalid Internal/External -> invalid
    $big[299][0] = 'G010';       // row 301: same Guest No. as row 11 -> duplicate in file
    $big[329][0] = '500';        // row 331: Guest No. of existing ATT-0001 -> duplicate of existing
    $decoyRows = [];
    for ($i = 1; $i <= 128; $i++) {
        $decoyRows[] = ['North', "Account {$i}", "acct{$i}@x.invalid"];
    }
    $file = workbook([
        ['External Customers', ['Cluster', 'Account Name', 'Email address'], $decoyRows],
        ['FINAL ATTENDEES', SIX, $big, ['highlightColumns' => [5], 'blankRows' => 639]],
    ], ['External Customers']);
    $bigParsed = $upload($file, 'final.xlsx');
    check('Parse: visible sheet, 361 rows (2-362), guest_list layout, hidden sheet reported', ($bigParsed['sheet'] ?? '') === 'FINAL ATTENDEES'
        && ($bigParsed['totalRows'] ?? 0) === 361 && ($bigParsed['firstRowNumber'] ?? 0) === 2 && ($bigParsed['lastRowNumber'] ?? 0) === 362
        && ($bigParsed['layout'] ?? '') === 'guest_list' && ($bigParsed['otherSheets'] ?? []) === [['name' => 'External Customers', 'hidden' => true]], array_diff_key($bigParsed, ['rows' => 1]));
    $bigPayload = $payload($bigParsed, null, 'final.xlsx');
    $bp = $admin->data('POST', '/attendees/import/preview', $bigPayload);
    check('Preview counts: 361 = 357 new + 2 invalid + 2 duplicate (1 in file, 1 existing)', ($bp['summary'] ?? null)
        === ['total' => 361, 'valid' => 357, 'invalid' => 2, 'duplicates' => 2, 'duplicatesExisting' => 1, 'duplicatesInFile' => 1], $bp['summary'] ?? $bp);
    $bBad = $byRow($bp['invalid'] ?? []);
    $bDup = $byRow($bp['duplicates'] ?? []);
    check('Invalid rows identified by spreadsheet row + Guest No. with the exact reason', array_keys($bBad) === [51, 121]
        && ($bBad[51]['externalIdentifier'] ?? '') === 'G050' && str_starts_with($bBad[51]['reasons'][0] ?? '', 'First Name is empty (column "FIRST NAME")')
        && ($bBad[121]['externalIdentifier'] ?? '') === 'G120' && str_contains($bBad[121]['reasons'][0] ?? '', '"GUEST"'), $bBad);
    check('Duplicates: row 301 = row 11 (Guest No. G010), row 331 = existing ATT-0001 (Guest No. 500)', ($bDup[301]['matches']['rowNumber'] ?? 0) === 11
        && ($bDup[331]['matches']['attendeeCode'] ?? '') === 'ATT-0001' && ($bDup[301]['matchedBy'] ?? '') === 'external_identifier', $bDup);
    check('Blank MIDDLE NAME rows are valid ("First1 Surname1"), with middle ("First9 M. Surname9")', ($bp['newPreview'][0]['fullName'] ?? '') === 'First1 Surname1'
        && ($bp['newPreview'][8]['fullName'] ?? '') === 'First9 M. Surname9', array_slice($bp['newPreview'] ?? [], 0, 9));
    $r = $admin->request('POST', '/attendees/import', $bigPayload);
    check('Import: 357 created = preview valid; summary identical to the preview', $r['status'] === 201 && ($r['body']['data']['imported'] ?? 0) === 357
        && ($r['body']['data']['summary'] ?? null) === ($bp['summary'] ?? 0), $r['status']);
    check('Database has exactly the 357 new G-guests, leading zeros kept (G001)', (int) $pdo->query("SELECT COUNT(*) FROM attendees WHERE event_id = {$eventId} AND external_identifier LIKE 'G%'")->fetchColumn() === 357
        && $pdo->query("SELECT full_name FROM attendees WHERE event_id = {$eventId} AND external_identifier = 'G001'")->fetchColumn() === 'First1 Surname1');
    check('Nothing from the hidden sheet was imported', (int) $pdo->query("SELECT COUNT(*) FROM attendees WHERE event_id = {$eventId} AND full_name LIKE 'Account %'")->fetchColumn() === 0);

    // -------------------------------------------------------- report + invariants
    section('Report and invariants');
    $rep = $admin->request('GET', "/reports/day-attendees.xlsx?day_id={$dayId}");
    $file = tempnam(sys_get_temp_dir(), 'ait-') . '.xlsx';
    file_put_contents($file, $rep['raw']);
    $sheet = SpreadsheetReader::read($file, 'xlsx');
    @unlink($file);
    $col = array_search('Internal/External', $sheet['headers'], true);
    $types = [];
    foreach ($sheet['rows'] as $line) {
        $types[$line['cells'][1]] = $line['cells'][$col !== false ? $col : 0] ?? '';
    }
    check('Day Attendees.xlsx: Internal/External appended last; EXTERNAL / INTERNAL / blank', $col === 9 && array_slice($sheet['headers'], 0, 9)
        === ['Attendee Code', 'Full Name', 'Company', 'Cluster', 'Employee ID', 'Email', 'Registration Status', 'Registered At', 'Registration Sessions']
        && ($types['Gerry sample Casiano'] ?? '') === 'EXTERNAL' && ($types['Maria Santos'] ?? '') === 'INTERNAL' && ($types['Legacy Person One'] ?? 'x') === '', $sheet['headers']);
    $after = array_slice($snapshot($eventId), 0, count($before));
    check('Existing attendees unchanged: same codes, names, Guest No., QR tokens, updated_at', $after === $before);
    check('No duplicate attendee codes in the event', (int) $pdo->query("SELECT COUNT(*) - COUNT(DISTINCT attendee_code) FROM attendees WHERE event_id = {$eventId}")->fetchColumn() === 0);
} finally {
    section('Cleanup');
    if ($eventId) {
        $admin->request('PATCH', "/events/{$eventId}/status", ['status' => 'archived']);
    }
    if ($originalActive !== null) {
        $restored = $admin->request('PATCH', "/events/{$originalActive}/status", ['status' => 'active'])['status'] === 200;
        echo $restored ? "  Restored the previously active event.\n" : "  WARNING: could not restore event #{$originalActive} to active.\n";
    }
    echo "  Test event archived.\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
