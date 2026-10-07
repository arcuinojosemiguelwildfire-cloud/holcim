<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `registration_sessions` (Phase 10, migration 033).
 *
 * An event day has one or more registration sessions (e.g. "Oct 15 - 8:00 AM"
 * and "Oct 15 - 5:00 PM"). At most one session per day is CURRENT
 * (is_current, enforced by UNIQUE current_lock); scans of that day are
 * recorded in the current session. counts_for_major decides whether a
 * registration in the session makes the attendee eligible for that day's
 * Major Randomizer. Sessions are configuration data - nothing is hard-coded.
 * Times are Asia/Manila wall-clock time.
 */
final class RegistrationSession
{
    private const COLUMNS = 'id, event_id, event_day_id, label, session_date, start_time, description, sort_order,
        is_enabled, counts_for_major, is_current, created_at, updated_at';

    /** Label of the session created automatically for a new day (and by migration 033). */
    public const DEFAULT_LABEL = 'Registration';

    /** @return list<array<string, mixed>> sessions of a day in display order */
    public static function forDay(int $dayId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM registration_sessions WHERE event_day_id = :day_id ORDER BY sort_order, start_time, id'
        );
        $statement->execute(['day_id' => $dayId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> sessions of every day of an event (day order, then session order) */
    public static function forEvent(int $eventId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT s.id, s.event_id, s.event_day_id, s.label, s.session_date, s.start_time, s.description, s.sort_order,
                    s.is_enabled, s.counts_for_major, s.is_current, s.created_at, s.updated_at
             FROM registration_sessions s JOIN event_days d ON d.id = s.event_day_id
             WHERE s.event_id = :event_id ORDER BY d.day_number, s.sort_order, s.start_time, s.id'
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM registration_sessions WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /**
     * The current session of a day. $lock = true takes a shared lock (inside a
     * transaction) so a session switch cannot happen half-way through a scan.
     *
     * @return array<string, mixed>|null
     */
    public static function currentForDay(int $dayId, bool $lock = false): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM registration_sessions WHERE event_day_id = :day_id AND is_current = 1 LIMIT 1'
            . ($lock ? ' LOCK IN SHARE MODE' : '')
        );
        $statement->execute(['day_id' => $dayId]);

        return $statement->fetch() ?: null;
    }

    /** Locks every session row of a day (call inside a transaction). */
    public static function lockDay(int $dayId): void
    {
        Database::connection()->prepare('SELECT id FROM registration_sessions WHERE event_day_id = :day_id FOR UPDATE')
            ->execute(['day_id' => $dayId]);
    }

    public static function nextSortOrder(int $dayId): int
    {
        $statement = Database::connection()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM registration_sessions WHERE event_day_id = :day_id');
        $statement->execute(['day_id' => $dayId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{label: string, session_date: string, start_time: ?string, description: ?string,
     *              sort_order: int, is_enabled: bool, counts_for_major: bool, is_current?: bool} $data
     */
    public static function create(int $eventId, int $dayId, array $data): int
    {
        Database::connection()->prepare(
            'INSERT INTO registration_sessions
                (event_id, event_day_id, label, session_date, start_time, description, sort_order, is_enabled, counts_for_major, is_current)
             VALUES (:event_id, :day_id, :label, :session_date, :start_time, :description, :sort_order, :is_enabled, :counts_for_major, :is_current)'
        )->execute([
            'event_id' => $eventId,
            'day_id' => $dayId,
            'label' => $data['label'],
            'session_date' => $data['session_date'],
            'start_time' => $data['start_time'],
            'description' => $data['description'],
            'sort_order' => $data['sort_order'],
            'is_enabled' => $data['is_enabled'] ? 1 : 0,
            'counts_for_major' => $data['counts_for_major'] ? 1 : 0,
            'is_current' => ($data['is_current'] ?? false) ? 1 : 0,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * @param array{label: string, session_date: string, start_time: ?string, description: ?string,
     *              sort_order: int, is_enabled: bool, counts_for_major: bool} $data
     */
    public static function update(int $id, array $data): void
    {
        Database::connection()->prepare(
            'UPDATE registration_sessions SET label = :label, session_date = :session_date, start_time = :start_time,
                description = :description, sort_order = :sort_order, is_enabled = :is_enabled, counts_for_major = :counts_for_major
             WHERE id = :id'
        )->execute([
            'label' => $data['label'],
            'session_date' => $data['session_date'],
            'start_time' => $data['start_time'],
            'description' => $data['description'],
            'sort_order' => $data['sort_order'],
            'is_enabled' => $data['is_enabled'] ? 1 : 0,
            'counts_for_major' => $data['counts_for_major'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    /** Makes $id the only current session of its day (call inside a transaction after lockDay()). */
    public static function setCurrent(int $dayId, int $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE registration_sessions SET is_current = 0 WHERE event_day_id = :day_id AND is_current = 1 AND id <> :id')
            ->execute(['day_id' => $dayId, 'id' => $id]);
        $pdo->prepare('UPDATE registration_sessions SET is_current = 1 WHERE id = :id')->execute(['id' => $id]);
    }

    /** Active registrations in the session. */
    public static function countRegistrations(int $id): int
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM session_registrations WHERE registration_session_id = :id AND status = 'active'"
        );
        $statement->execute(['id' => $id]);

        return (int) $statement->fetchColumn();
    }

    /** "5:00 PM" (empty when the session has no start time). */
    public static function timeLabel(array $session): string
    {
        $time = $session['start_time'] ?? null;

        return $time === null || $time === '' ? '' : date('g:i A', (int) strtotime('2000-01-01 ' . $time));
    }

    /** "October 15" */
    public static function dateLabel(array $session): string
    {
        return date('F j', (int) strtotime((string) $session['session_date']));
    }

    /**
     * Public shape (no internal flags beyond what the UI needs).
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public static function toPublic(array $session, ?int $registrations = null): array
    {
        $public = [
            'id' => (int) $session['id'],
            'eventDayId' => (int) $session['event_day_id'],
            'label' => $session['label'],
            'sessionDate' => $session['session_date'],
            'startTime' => $session['start_time'] !== null ? substr((string) $session['start_time'], 0, 5) : null,
            'description' => $session['description'],
            'sortOrder' => (int) $session['sort_order'],
            'enabled' => (bool) $session['is_enabled'],
            'countsForMajor' => (bool) $session['counts_for_major'],
            'current' => (bool) $session['is_current'],
            'dateLabel' => self::dateLabel($session),
            'timeLabel' => self::timeLabel($session),
            'updatedAt' => $session['updated_at'],
        ];
        if ($registrations !== null) {
            $public['registrations'] = $registrations;
        }

        return $public;
    }

    /**
     * What the registration desk shows - no ids, no eligibility internals.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public static function toDesk(array $session): array
    {
        return [
            'label' => $session['label'],
            'dateLabel' => self::dateLabel($session),
            'timeLabel' => self::timeLabel($session),
            'sessionDate' => $session['session_date'],
            'startTime' => $session['start_time'] !== null ? substr((string) $session['start_time'], 0, 5) : null,
        ];
    }
}
