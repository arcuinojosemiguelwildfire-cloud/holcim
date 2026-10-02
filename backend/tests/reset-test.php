<?php

declare(strict_types=1);

/**
 * System Reset test (Phase 9.3) - DESTRUCTIVE, TEST DATABASE ONLY.
 *
 *   DB_DATABASE=holcim_test php backend/tests/reset-test.php --confirm-reset
 *
 * Seeds representative event/test data (event, two event days, attendees,
 * QR tokens, registrations, scan logs, Minor/Major draws incl. a voided one,
 * manual raffle participants, legacy Major rows, an import batch, login
 * attempts, audit logs, a scanner operator, registration staff and an event
 * operator), runs SystemResetService::run() and verifies:
 *   - every event/test table is empty
 *   - admin account(s) are untouched (id, email, username, role, hash) and
 *     the admin password still authenticates
 *   - every non-admin user is gone
 *   - the schema is intact (same tables/columns, schema_migrations kept)
 *   - no orphan rows (generic check over every foreign key)
 *
 * Safety: refuses to run unless ALL of the following hold
 *   - run from the CLI with --confirm-reset
 *   - the database name contains "test"
 *   - APP_ENV is not "production"
 * It never runs automatically (not referenced by migrations, startup,
 * deployment or the other test scripts).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Services\SystemResetService;

$database = (string) Config::get('database.database', '');
if (!in_array('--confirm-reset', $argv, true)) {
    fwrite(STDERR, "Refusing to run: this test DELETES all event data in '{$database}'.\n");
    fwrite(STDERR, "Run against a TEST database only:  DB_DATABASE=holcim_test php backend/tests/reset-test.php --confirm-reset\n");
    exit(2);
}
if (!str_contains(strtolower($database), 'test')) {
    fwrite(STDERR, "Refusing to run: database '{$database}' is not a test database (name must contain 'test').\n");
    exit(2);
}
if (strtolower((string) Env::get('APP_ENV', '')) === 'production') {
    fwrite(STDERR, "Refusing to run: APP_ENV=production.\n");
    exit(2);
}

$pdo = Database::connection();
$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
}
function scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchColumn();
}
function insert(PDO $pdo, string $table, array $row): int
{
    $columns = array_keys($row);
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')')
        ->execute($row);

    return (int) $pdo->lastInsertId();
}
/** @return array<string, list<string>> table => sorted columns */
function schemaSnapshot(PDO $pdo, string $database): array
{
    $statement = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME, ORDINAL_POSITION');
    $statement->execute(['db' => $database]);
    $schema = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $schema[$row['TABLE_NAME']][] = $row['COLUMN_NAME'] . ' ' . $row['COLUMN_TYPE'];
    }

    return $schema;
}

echo "System reset test against TEST database '{$database}'\n";

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$required = ['events', 'event_days', 'attendees', 'attendee_qr_codes', 'registration_scans', 'scan_logs', 'randomizer_draws',
    'minor_manual_entries', 'major_eligibility', 'major_entries', 'import_batches', 'login_attempts', 'audit_logs', 'users', 'schema_migrations'];
if (array_diff($required, $tables) !== []) {
    fwrite(STDERR, "The test database is not fully migrated. Run: DB_DATABASE={$database} php backend/cli/migrate.php\n");
    exit(1);
}

// ---------------------------------------------------------------- seed
$adminPassword = 'Reset-Test-Admin-' . bin2hex(random_bytes(4));
$adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
$suffix = bin2hex(random_bytes(3));
$adminId = insert($pdo, 'users', ['name' => 'Reset Test Admin', 'email' => "reset-admin-{$suffix}@example.test", 'password_hash' => $adminHash, 'role' => 'admin', 'status' => 'active']);
$admin2Id = insert($pdo, 'users', ['name' => 'Second Admin', 'email' => "reset-admin2-{$suffix}@example.test", 'password_hash' => $adminHash, 'role' => 'admin', 'status' => 'inactive']);
$staffId = insert($pdo, 'users', ['name' => 'Reg Staff', 'email' => "staff-{$suffix}@example.test", 'password_hash' => $adminHash, 'role' => 'registration_staff', 'status' => 'active']);
$operatorId = insert($pdo, 'users', ['name' => 'Event Operator', 'email' => "op-{$suffix}@example.test", 'password_hash' => $adminHash, 'role' => 'event_operator', 'status' => 'active']);
$scannerId = insert($pdo, 'users', ['name' => 'Scanner One', 'username' => "scanner-{$suffix}", 'password_hash' => $adminHash, 'role' => 'scanner_operator', 'status' => 'active']);

$eventId = insert($pdo, 'events', ['name' => 'Reset Test Event', 'event_date' => '2026-12-01', 'status' => 'draft']);
$day1 = insert($pdo, 'event_days', ['event_id' => $eventId, 'day_number' => 1, 'event_date' => '2026-12-01', 'status' => 'completed']);
$day2 = insert($pdo, 'event_days', ['event_id' => $eventId, 'day_number' => 2, 'event_date' => '2026-12-02', 'status' => 'active']);
$batchId = insert($pdo, 'import_batches', ['event_id' => $eventId, 'import_type' => 'attendees', 'original_filename' => 'synthetic.xlsx', 'status' => 'completed', 'imported_by' => $adminId]);

$attendees = [];
for ($i = 1; $i <= 6; $i++) {
    $attendees[$i] = insert($pdo, 'attendees', [
        'event_id' => $eventId, 'attendee_code' => sprintf('ATT-%04d', $i), 'full_name' => "Test Person {$i}",
        'company' => 'Company ' . ($i % 2 ? 'A' : 'B'), 'department' => 'North Luzon', 'import_batch_id' => $i <= 4 ? $batchId : null,
    ]);
    insert($pdo, 'attendee_qr_codes', ['attendee_id' => $attendees[$i], 'token' => bin2hex(random_bytes(16))]);
}
foreach ([1, 2, 3] as $i) {
    insert($pdo, 'registration_scans', ['event_id' => $eventId, 'event_day_id' => $day1, 'attendee_id' => $attendees[$i], 'scanner_user_id' => $scannerId]);
    insert($pdo, 'scan_logs', ['event_id' => $eventId, 'event_day_id' => $day1, 'attendee_id' => $attendees[$i], 'result' => 'registered', 'user_id' => $scannerId]);
}
insert($pdo, 'registration_scans', ['event_id' => $eventId, 'event_day_id' => $day2, 'attendee_id' => $attendees[1], 'scanner_user_id' => $staffId]);
insert($pdo, 'scan_logs', ['event_id' => $eventId, 'event_day_id' => $day2, 'attendee_id' => null, 'result' => 'invalid_qr', 'user_id' => $staffId]);
insert($pdo, 'minor_manual_entries', ['event_id' => $eventId, 'event_day_id' => $day1, 'attendee_id' => $attendees[5], 'added_by' => $operatorId]);
insert($pdo, 'major_eligibility', ['event_id' => $eventId, 'event_day_id' => $day1, 'attendee_id' => $attendees[6], 'source' => 'manual']);
insert($pdo, 'major_eligibility', ['event_id' => $eventId, 'event_day_id' => $day1, 'attendee_id' => $attendees[4], 'source' => 'import']);
insert($pdo, 'major_entries', ['event_id' => $eventId, 'full_name' => 'Legacy Major Entry']);
insert($pdo, 'randomizer_draws', ['event_id' => $eventId, 'event_day_id' => $day1, 'attendee_id' => $attendees[1], 'randomizer_type' => 'minor', 'drawn_by' => $operatorId]);
insert($pdo, 'randomizer_draws', ['event_id' => $eventId, 'event_day_id' => $day1, 'attendee_id' => $attendees[2], 'randomizer_type' => 'major', 'drawn_by' => $operatorId]);
$void = insert($pdo, 'randomizer_draws', ['event_id' => $eventId, 'event_day_id' => $day1, 'attendee_id' => $attendees[3], 'randomizer_type' => 'minor', 'drawn_by' => $operatorId]);
$pdo->prepare("UPDATE randomizer_draws SET voided_by = :u, voided_at = NOW(), void_reason = 'test' WHERE id = :id")
    ->execute(['u' => $adminId, 'id' => $void]);
insert($pdo, 'login_attempts', ['email_hash' => hash('sha256', 'x'), 'ip_address' => '127.0.0.1']);
insert($pdo, 'audit_logs', ['action' => 'test.seed', 'user_id' => $operatorId, 'event_id' => $eventId]);
insert($pdo, 'audit_logs', ['action' => 'auth.login', 'user_id' => $adminId]);

$adminsBefore = $pdo->query("SELECT id, name, email, username, password_hash, role, status FROM users WHERE role = 'admin' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$schemaBefore = schemaSnapshot($pdo, $database);
$migrationsBefore = $pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);

$seededCounts = SystemResetService::counts();
check('seed: event data present before reset', $seededCounts['events'] >= 1 && $seededCounts['attendees'] >= 6 && $seededCounts['randomizer_draws'] >= 3 && $seededCounts['non_admin_users'] >= 3);

// ---------------------------------------------------------------- reset
$deleted = SystemResetService::run();
check('reset: returned per-table counts', ($deleted['attendees'] ?? 0) >= 6 && ($deleted['users'] ?? 0) >= 3, json_encode($deleted));

// ---------------------------------------------------------------- verify
foreach (array_diff($tables, ['users', 'schema_migrations']) as $table) {
    $count = (int) scalar($pdo, "SELECT COUNT(*) FROM `{$table}`");
    check("removed: {$table} is empty", $count === 0, "{$count} row(s) left");
}
check('removed: no registration staff / event operator / scanner operator users', (int) scalar($pdo, "SELECT COUNT(*) FROM users WHERE role <> 'admin'") === 0);
foreach ([$staffId, $operatorId, $scannerId] as $userId) {
    check("removed: non-admin user #{$userId}", scalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $userId]) == 0);
}

$adminsAfter = $pdo->query("SELECT id, name, email, username, password_hash, role, status FROM users WHERE role = 'admin' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check('admin: every admin account preserved unchanged (id, name, email, username, password, role, status)', $adminsAfter === $adminsBefore);
check('admin: no admin account was created', count($adminsAfter) === count($adminsBefore));
$hash = (string) scalar($pdo, 'SELECT password_hash FROM users WHERE id = :id', ['id' => $adminId]);
check('admin: password still authenticates', password_verify($adminPassword, $hash));
check('admin: wrong password still rejected', !password_verify($adminPassword . 'x', $hash));
check('admin: account still active', scalar($pdo, 'SELECT status FROM users WHERE id = :id', ['id' => $adminId]) === 'active');
check('admin: second (inactive) admin preserved', scalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $admin2Id]) == 1);

check('schema: tables and columns unchanged', schemaSnapshot($pdo, $database) === $schemaBefore);
check('schema: schema_migrations unchanged', $pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN) === $migrationsBefore);

// Generic orphan check over every foreign key in the schema.
// Composite keys are grouped by constraint so the join uses every column.
$fks = $pdo->prepare('SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION');
$fks->execute(['db' => $database]);
$constraints = [];
foreach ($fks->fetchAll(PDO::FETCH_ASSOC) as $fk) {
    $constraints[$fk['TABLE_NAME'] . '.' . $fk['CONSTRAINT_NAME']]['child'] = $fk['TABLE_NAME'];
    $constraints[$fk['TABLE_NAME'] . '.' . $fk['CONSTRAINT_NAME']]['parent'] = $fk['REFERENCED_TABLE_NAME'];
    $constraints[$fk['TABLE_NAME'] . '.' . $fk['CONSTRAINT_NAME']]['pairs'][] = [$fk['COLUMN_NAME'], $fk['REFERENCED_COLUMN_NAME']];
}
$orphans = 0;
foreach ($constraints as $fk) {
    $on = implode(' AND ', array_map(static fn (array $p): string => "p.`{$p[1]}` = c.`{$p[0]}`", $fk['pairs']));
    $notNull = implode(' AND ', array_map(static fn (array $p): string => "c.`{$p[0]}` IS NOT NULL", $fk['pairs']));
    $orphans += (int) scalar($pdo, "SELECT COUNT(*) FROM `{$fk['child']}` c LEFT JOIN `{$fk['parent']}` p ON {$on}
        WHERE {$notNull} AND p.`{$fk['pairs'][0][1]}` IS NULL");
}
check('integrity: foreign keys inspected', count($constraints) >= 20, (string) count($constraints));
check('integrity: no orphan rows across all foreign keys', $orphans === 0, "{$orphans} orphan(s)");

// The reset is all-or-nothing: a failing run rolls back.
$pdo->exec("INSERT INTO events (name, event_date, status) VALUES ('Rollback probe', '2026-12-01', 'draft')");
$pdo->exec('DROP TRIGGER IF EXISTS reset_test_block_users');
$pdo->exec("CREATE TRIGGER reset_test_block_users BEFORE DELETE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'");
$insertedProbeUser = insert($pdo, 'users', ['name' => 'Probe Staff', 'email' => "probe-{$suffix}@example.test", 'password_hash' => $adminHash, 'role' => 'registration_staff', 'status' => 'active']);
$threw = false;
try {
    SystemResetService::run();
} catch (\Throwable) {
    $threw = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS reset_test_block_users');
check('rollback: a failure part-way aborts the reset', $threw);
check('rollback: earlier deletes in the failed run were rolled back', (int) scalar($pdo, "SELECT COUNT(*) FROM events WHERE name = 'Rollback probe'") === 1);
SystemResetService::run();
check('cleanup: second reset clears the probe rows', (int) scalar($pdo, 'SELECT COUNT(*) FROM events') === 0
    && scalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $insertedProbeUser]) == 0);

// Remove the admins this test created (test DB housekeeping only).
$pdo->prepare('DELETE FROM users WHERE id IN (:a, :b)')->execute(['a' => $adminId, 'b' => $admin2Id]);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
