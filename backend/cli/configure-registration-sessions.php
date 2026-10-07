<?php

declare(strict_types=1);

/**
 * Configures registration sessions for an event from a JSON file (Phase 10).
 * Sessions are DATA: the file describes them; nothing is hard-coded.
 *
 *   php backend/cli/configure-registration-sessions.php --file=database/registration-sessions/holcim-2026.json
 *       DRY RUN (default): prints what would change, writes nothing.
 *   php backend/cli/configure-registration-sessions.php --file=... --apply
 *       Applies the plan in ONE transaction (all or nothing) and audit-logs it.
 *   --event=ID   event to configure (default: the active event)
 *
 * File format:
 *   {"days": [{"date": "2026-10-14", "sessions": [
 *       {"label": "Oct 14 - 5:00 PM", "start_time": "17:00", "counts_for_major": true, "current": true,
 *        "description": "optional"}]}]}
 *
 * Matching (never destructive, never guesses):
 *   - Days are matched by date; a missing day is an error (add it on the Events page first).
 *   - A configured session is matched to an existing session of that day by label
 *     (case-insensitive) and updated in place - its registrations stay attached.
 *   - Otherwise, the day's automatic default session ("Registration", no start time) is
 *     adopted (renamed/updated, registrations kept) when the day has exactly ONE configured
 *     session (e.g. Oct 14 -> "Oct 14 - 5:00 PM"), or when it has no registrations yet.
 *     If it has registrations and the day gets several sessions, the tool STOPS: an admin
 *     must decide which session those registrations belong to.
 *   - Otherwise a new session is created. Existing sessions not in the file are left as is.
 *   - "current": true makes that session the current one of its day ONLY when the day's
 *     current session is not already one of the configured sessions - so re-running the
 *     file during the event never switches an admin's 5:00 PM choice back to 8:00 AM.
 * Attendees, QR codes, registrations, draws and scan logs are never changed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RegistrationSession;

$options = getopt('', ['file:', 'event:', 'apply', 'help']);
if (isset($options['help']) || !isset($options['file'])) {
    fwrite(isset($options['help']) ? STDOUT : STDERR, "Usage: php backend/cli/configure-registration-sessions.php --file=FILE.json [--event=ID] [--apply]\n");
    exit(isset($options['help']) ? 0 : 2);
}
$fail = static function (string $message): never {
    fwrite(STDERR, "ERROR: {$message}\nNothing was changed.\n");
    exit(1);
};

$config = json_decode((string) @file_get_contents((string) $options['file']), true);
if (!is_array($config) || !is_array($config['days'] ?? null) || $config['days'] === []) {
    $fail('The file is missing or is not valid JSON with a "days" list.');
}
$event = isset($options['event']) ? Event::find((int) $options['event']) : Event::findActive();
if ($event === null) {
    $fail(isset($options['event']) ? "Event #{$options['event']} not found." : 'There is no active event (use --event=ID).');
}
$eventId = (int) $event['id'];
$apply = isset($options['apply']);
$days = [];
foreach (EventDay::forEvent($eventId) as $day) {
    $days[(string) $day['event_date']] = $day;
}

// ---------------------------------------------------------------- plan
$plan = [];
foreach ($config['days'] as $n => $dayConfig) {
    $date = (string) ($dayConfig['date'] ?? '');
    $sessions = $dayConfig['sessions'] ?? null;
    if (!isset($days[$date])) {
        $fail("Day {$date} does not exist in event \"{$event['name']}\". Add it on the Events page first.");
    }
    if (!is_array($sessions) || $sessions === []) {
        $fail("Day {$date}: \"sessions\" must be a non-empty list.");
    }
    $day = $days[$date];
    $existing = RegistrationSession::forDay((int) $day['id']);
    $used = [];
    $currents = 0;
    foreach (array_values($sessions) as $i => $s) {
        $label = trim((string) ($s['label'] ?? ''));
        $time = (string) ($s['start_time'] ?? '');
        if ($label === '' || mb_strlen($label) > 100) {
            $fail("Day {$date}, session " . ($i + 1) . ': label is required (max 100 characters).');
        }
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) !== 1) {
            $fail("Day {$date}, \"{$label}\": start_time must be HH:MM (24-hour).");
        }
        if (!is_bool($s['counts_for_major'] ?? null)) {
            $fail("Day {$date}, \"{$label}\": counts_for_major must be true or false.");
        }
        $currents += !empty($s['current']) ? 1 : 0;
        $data = [
            'label' => $label,
            'session_date' => (string) ($s['session_date'] ?? $date),
            'start_time' => "{$time}:00",
            'description' => isset($s['description']) ? mb_substr((string) $s['description'], 0, 255) : null,
            'sort_order' => $i + 1,
            'is_enabled' => true,
            'counts_for_major' => (bool) $s['counts_for_major'],
        ];
        $match = null;
        foreach ($existing as $e) {
            if (!isset($used[(int) $e['id']]) && mb_strtolower($e['label']) === mb_strtolower($label)) {
                $match = $e;
                break;
            }
        }
        if ($match === null) {
            foreach ($existing as $e) {
                $isDefault = !isset($used[(int) $e['id']]) && $e['label'] === RegistrationSession::DEFAULT_LABEL && $e['start_time'] === null;
                if (!$isDefault) {
                    continue;
                }
                $registrations = RegistrationSession::countRegistrations((int) $e['id']);
                if (count($sessions) > 1 && $registrations > 0) {
                    $fail("Day {$date} already has {$registrations} registration(s) in its default session and the file gives it "
                        . count($sessions) . " sessions. Which session those registrations belong to is an admin decision: rename the default session "
                        . 'on the Events page (Registration sessions) to the right one, then run this again.');
                }
                $match = $e;
                break;
            }
        }
        if ($match !== null) {
            $used[(int) $match['id']] = true;
        }
        $plan[] = ['day' => $day, 'existing' => $match, 'data' => $data, 'current' => !empty($s['current'])];
    }
    if ($currents > 1) {
        $fail("Day {$date}: only one session can be \"current\".");
    }
    $dayPlan = array_slice($plan, -count($sessions));
    $alreadyCurrent = array_filter($dayPlan, static fn (array $p): bool => $p['existing'] !== null && (bool) $p['existing']['is_current']
        && !($p['existing']['label'] === RegistrationSession::DEFAULT_LABEL && $p['existing']['start_time'] === null));
    if ($alreadyCurrent !== []) {
        foreach (array_keys($dayPlan) as $k) {
            $plan[count($plan) - count($sessions) + $k]['current'] = false;
        }
        echo "  note: Day {$date} keeps its current session \"" . array_values($alreadyCurrent)[0]['existing']['label'] . "\" (already configured).\n";
    }
    foreach ($existing as $e) {
        if (!isset($used[(int) $e['id']])) {
            echo "  note: Day {$date} keeps its other session \"{$e['label']}\" (not in the file; unchanged).\n";
        }
    }
}

echo ($apply ? 'Applying' : 'DRY RUN - would apply') . " registration sessions to \"{$event['name']}\" (#{$eventId}):\n";
foreach ($plan as $p) {
    $d = $p['data'];
    $what = $p['existing'] === null ? 'CREATE' : "UPDATE #{$p['existing']['id']} (was \"{$p['existing']['label']}\", "
        . RegistrationSession::countRegistrations((int) $p['existing']['id']) . ' registration(s) kept)';
    printf("  %s  %-24s %s %s  Major: %-3s%s  %s\n", $p['day']['event_date'], $d['label'], $d['session_date'], substr($d['start_time'], 0, 5),
        $d['counts_for_major'] ? 'YES' : 'NO', $p['current'] ? '  [current]' : '', $what);
}
if (!$apply) {
    echo "\nNothing was changed. Re-run with --apply to save.\n";
    exit(0);
}

// ---------------------------------------------------------------- apply
$log = [];
Database::transaction(static function () use ($plan, $eventId, &$log): void {
    foreach (array_unique(array_map(static fn ($p) => (int) $p['day']['id'], $plan)) as $dayId) {
        RegistrationSession::lockDay($dayId);
    }
    foreach ($plan as $i => $p) {
        $dayId = (int) $p['day']['id'];
        if ($p['existing'] === null) {
            $id = RegistrationSession::create($eventId, $dayId, $p['data'] + ['is_current' => false]);
            $log[] = ['registration_session.created', "Added registration session \"{$p['data']['label']}\" (CLI).", $id, $dayId];
        } else {
            $id = (int) $p['existing']['id'];
            RegistrationSession::update($id, $p['data']);
            $log[] = ['registration_session.updated', "Updated registration session \"{$p['existing']['label']}\" -> \"{$p['data']['label']}\" (CLI).", $id, $dayId];
        }
        $plan[$i]['id'] = $id;
    }
    foreach ($plan as $p) {
        if ($p['current']) {
            RegistrationSession::setCurrent((int) $p['day']['id'], (int) $p['id']);
            $log[] = ['registration_session.changed', "Current registration session set to \"{$p['data']['label']}\" (CLI).", (int) $p['id'], (int) $p['day']['id']];
        }
    }
});
foreach ($log as [$action, $description, $sessionId, $dayId]) {
    AuditLog::create($action, null, $eventId, $description, ['registration_session_id' => $sessionId, 'event_day_id' => $dayId, 'source' => 'cli'], 'cli', 'configure-registration-sessions.php');
}
echo "\nDone. " . count($plan) . " session(s) saved.\n";
