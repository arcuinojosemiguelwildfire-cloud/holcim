<?php

declare(strict_types=1);

/**
 * Event-day readiness check (Phase 7). Read-only; prints no secrets.
 *
 *   php backend/cli/check-readiness.php
 *
 * Exit code 0 = no FAIL items (WARN items still deserve a look).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$results = [];
$add = static function (string $status, string $label, string $detail = '') use (&$results): void {
    $results[] = [$status, $label, $detail];
};

$env = (string) Config::get('app.env');
$production = $env === 'production';
$add($production ? 'OK' : 'WARN', 'APP_ENV', $env . ($production ? '' : ' (use "production" on the event server)'));
$add(Config::get('app.debug') ? ($production ? 'FAIL' : 'WARN') : 'OK', 'APP_DEBUG', Config::get('app.debug') ? 'true (must be false in production)' : 'false');

$appUrl = (string) Config::get('app.url', '');
if ($appUrl === '') {
    $add('FAIL', 'APP_URL', 'empty - attendee QR codes would contain only the token; set it before printing');
} elseif (preg_match('#localhost|127\.0\.0\.1|//192\.168\.|//10\.#i', $appUrl)) {
    $add($production ? 'FAIL' : 'WARN', 'APP_URL', "{$appUrl} is a local address - do not print event QR codes with it");
} elseif (!str_starts_with(strtolower($appUrl), 'https://')) {
    $add('FAIL', 'APP_URL', "{$appUrl} is not HTTPS - the registration camera needs HTTPS");
} else {
    $add('OK', 'APP_URL', $appUrl);
}

$secure = (bool) Config::get('session.secure');
$add($secure ? 'OK' : ($production ? 'FAIL' : 'WARN'), 'SESSION_SECURE_COOKIE', $secure ? 'true' : 'false (must be true on HTTPS)');

$form = trim((string) Config::get('app.major_form_url', ''));
$validForm = $form !== '' && filter_var($form, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $form);
$add($validForm ? 'OK' : 'WARN', 'MAJOR_FORM_URL', $validForm ? (string) parse_url($form, PHP_URL_HOST) . ' (configured)' : 'not set or invalid - /major-form shows "not available yet"');

foreach (['zip' => 'XLSX imports', 'mbstring' => 'text handling', 'pdo_mysql' => 'database', 'simplexml' => 'XLSX imports'] as $ext => $why) {
    $add(extension_loaded($ext) ? 'OK' : 'FAIL', "PHP extension {$ext}", extension_loaded($ext) ? '' : "missing ({$why})");
}

try {
    $pdo = Database::connection();
    $add('OK', 'Database connection', (string) Config::get('database.database'));

    $applied = $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $files = count(glob(PROJECT_ROOT . '/database/migrations/*.sql') ?: []);
    $add((int) $applied === $files ? 'OK' : 'FAIL', 'Migrations', "{$applied} of {$files} applied" . ((int) $applied === $files ? '' : ' - run php backend/cli/migrate.php'));

    $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
    $add($admins > 0 ? 'OK' : 'FAIL', 'Active admin account', (string) $admins);

    $scannerOps = $pdo->query("SELECT SUM(status = 'active') AS active, COUNT(*) AS total FROM users WHERE role = 'scanner_operator'")->fetch();
    $activeOps = (int) ($scannerOps['active'] ?? 0);
    $add($activeOps > 0 ? 'OK' : 'INFO', 'Scanner operators', "{$activeOps} active of " . (int) ($scannerOps['total'] ?? 0)
        . ($activeOps > 0 ? '' : ' - add them in Settings > Scanner Operators if needed'));

    $event = $pdo->query("SELECT id, name FROM events WHERE status = 'active' LIMIT 1")->fetch();
    if (!$event) {
        $add('FAIL', 'Active event', 'none - set one on the Events page');
    } else {
        $add('OK', 'Active event', $event['name']);
        $id = (int) $event['id'];
        $count = static fn (string $sql): int => (int) (function () use ($pdo, $sql, $id) {
            $s = $pdo->prepare($sql);
            $s->execute(['id' => $id]);

            return $s->fetchColumn();
        })();
        $attendees = $count("SELECT COUNT(*) FROM attendees WHERE event_id = :id AND status = 'active'");
        $missingQr = $count("SELECT COUNT(*) FROM attendees a LEFT JOIN attendee_qr_codes q ON q.attendee_id = a.id WHERE a.event_id = :id AND a.status = 'active' AND q.id IS NULL");
        $add($attendees > 0 ? 'OK' : 'WARN', 'Active attendees', (string) $attendees);
        $add($missingQr === 0 ? 'OK' : 'WARN', 'Attendees without a QR', (string) $missingQr);

        // Phase 8: event days of the active event and the current day.
        $days = $pdo->prepare('SELECT day_number, event_date, status FROM event_days WHERE event_id = :id ORDER BY day_number');
        $days->execute(['id' => $id]);
        $dayRows = $days->fetchAll();
        $add($dayRows !== [] ? 'OK' : 'FAIL', 'Event days', $dayRows === [] ? 'none - add a day on the Events page' : count($dayRows) . ' configured: '
            . implode(', ', array_map(static fn (array $d): string => "Day {$d['day_number']} {$d['event_date']} ({$d['status']})", $dayRows)));
        $activeDay = array_values(array_filter($dayRows, static fn (array $d): bool => $d['status'] === 'active'))[0] ?? null;
        $add($activeDay !== null ? 'OK' : 'FAIL', 'Current event day', $activeDay !== null
            ? "Day {$activeDay['day_number']} - {$activeDay['event_date']}" . ($activeDay['event_date'] !== date('Y-m-d') ? ' (not today\'s date - check before doors open)' : '')
            : 'none - activate a day on the Events page');
        if ($appUrl !== '') {
            $s = $pdo->prepare('SELECT COUNT(*) FROM attendee_qr_codes q JOIN attendees a ON a.id = q.attendee_id WHERE a.event_id = :id');
            $s->execute(['id' => $id]);
            $add('INFO', 'Attendee QR codes', $s->fetchColumn() . " issued; they encode {$appUrl}/q/<token>");
        }
    }
} catch (\Throwable $exception) {
    $add('FAIL', 'Database connection', 'cannot connect - check DB_* settings (' . get_class($exception) . ')');
}

$failed = 0;
foreach ($results as [$status, $label, $detail]) {
    $failed += $status === 'FAIL' ? 1 : 0;
    printf("[%-4s] %-26s %s\n", $status, $label, $detail);
}
echo $failed === 0 ? "\nNo blocking problems found.\n" : "\n{$failed} blocking problem(s) found.\n";
exit($failed === 0 ? 0 : 1);
