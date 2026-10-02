<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\User;

/**
 * Settings > System Reset (admin only): removes all event / test data so the
 * real event starts from a clean database. DESTRUCTIVE.
 *
 * Preserved: every admin account (name, email/username, password, role,
 * status), the schema, schema_migrations and configuration.
 * Removed: events, event days, attendees, QR tokens, registrations, scan
 * logs, randomizer draws, manual raffle participants, Major eligibility
 * (incl. legacy imported rows), legacy major_entries, import batches,
 * audit logs, login attempts and every non-admin user (registration staff,
 * event operators, scanner operators).
 *
 * Rows are deleted child-first in ONE transaction (DELETE, not TRUNCATE, so
 * it can roll back); any failure rolls everything back. One audit record
 * "system.reset" by the admin is written afterwards.
 */
final class SystemResetService
{
    public const CONFIRMATION_PHRASE = 'RESET EVENT DATA';

    /**
     * Delete order respects the foreign keys (children before parents), so no
     * RESTRICT constraint fails and no orphan rows remain.
     *
     * @var list<array{0: string, 1: string}> [table, optional WHERE]
     */
    private const DELETE_ORDER = [
        ['scan_logs', ''],
        ['randomizer_draws', ''],
        ['minor_manual_entries', ''],
        ['major_eligibility', ''],
        ['major_entries', ''],
        ['registration_scans', ''],
        ['attendee_qr_codes', ''],
        ['attendees', ''],
        ['import_batches', ''],
        ['event_days', ''],
        ['audit_logs', ''],
        ['events', ''],
        ['login_attempts', ''],
        ['users', "role <> 'admin'"],
    ];

    /**
     * Verifies the admin's confirmation, then resets.
     *
     * @return array{deleted: array<string, int>, adminsPreserved: int}
     */
    public static function reset(Request $request, string $confirmation, string $password): array
    {
        $admin = AuthService::currentUser();
        if ($admin === null || $admin['role'] !== User::ROLE_ADMIN) {
            throw HttpException::forbidden();
        }

        $errors = [];
        if (trim($confirmation) !== self::CONFIRMATION_PHRASE) {
            $errors['confirmation'][] = 'Type ' . self::CONFIRMATION_PHRASE . ' exactly to confirm.';
        }
        $hash = User::passwordHashFor((int) $admin['id']);
        if ($password === '' || $hash === null || !password_verify($password, $hash)) {
            $errors['password'][] = 'Your current password is incorrect.';
        }
        if ($errors !== []) {
            AuditLogger::log($request, 'system.reset_refused', 'System reset refused (confirmation or password incorrect).');
            throw HttpException::validation($errors, 'The reset was not performed.');
        }

        try {
            $deleted = self::run();
        } catch (\Throwable $exception) {
            error_log('System reset failed: ' . $exception->getMessage());
            throw new HttpException(500, 'RESET_FAILED', 'The reset failed and nothing was changed. Please try again or check the server log.');
        }

        $admins = User::countActiveWithRole(User::ROLE_ADMIN);
        AuditLogger::log(
            $request,
            'system.reset',
            "System reset by {$admin['name']}: all event/test data removed; admin accounts preserved.",
            metadata: ['deleted' => $deleted]
        );

        return ['deleted' => $deleted, 'adminsPreserved' => $admins];
    }

    /**
     * Deletes all event/test data in one transaction (no permission checks;
     * callers must authorise). Returns rows deleted per table.
     *
     * @return array<string, int>
     */
    public static function run(): array
    {
        return Database::transaction(static function (\PDO $pdo): array {
            $deleted = [];
            foreach (self::DELETE_ORDER as [$table, $where]) {
                $deleted[$table] = (int) $pdo->exec("DELETE FROM {$table}" . ($where !== '' ? " WHERE {$where}" : ''));
            }
            if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() === 0) {
                throw new \RuntimeException('No admin account would remain.');
            }

            return $deleted;
        });
    }

    /** Current row counts of the tables the reset clears (for the Settings page). */
    public static function counts(): array
    {
        $pdo = Database::connection();
        $counts = [];
        foreach (['events', 'event_days', 'attendees', 'registration_scans', 'randomizer_draws', 'import_batches'] as $table) {
            $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }
        $counts['manual_participants'] = (int) $pdo->query('SELECT (SELECT COUNT(*) FROM minor_manual_entries) + (SELECT COUNT(*) FROM major_eligibility)')->fetchColumn();
        $counts['non_admin_users'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'admin'")->fetchColumn();
        $counts['admin_users'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

        return $counts;
    }
}
