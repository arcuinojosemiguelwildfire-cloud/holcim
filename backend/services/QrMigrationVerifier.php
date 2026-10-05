<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Local -> Online QR migration verification (read-only).
 *
 * A printed QR contains only attendee_qr_codes.token. It stays usable after
 * a database move only if every token is still attached to the same
 * attendee (event_id + attendee_code). This class reads the QR identity
 * dataset, checks its integrity and compares it with a manifest saved
 * before the move. It never writes: snapshot() runs inside a READ ONLY
 * transaction that is always rolled back.
 *
 * Canonical dataset (for the SHA-256 fingerprint), one line per record:
 *   event_id|attendee_id|attendee_code|full_name|company|department|token\n
 *   - sorted by event_id (numeric), attendee_code (byte order), attendee_id, token
 *   - every line ends with LF ("\n"), UTF-8 bytes exactly as stored
 *   - NULL is written as \N; inside values "\" -> "\\", "|" -> "\|",
 *     LF -> "\n", CR -> "\r" (so values can never break the format)
 *   - tokens are never trimmed, lower-cased or normalised
 * Identity fingerprint: same rules, fields event_id|attendee_code|token only
 * (independent of auto-increment attendee IDs and of name/company/cluster).
 */
final class QrMigrationVerifier
{
    public const MANIFEST_VERSION = 1;
    /** Format of tokens issued by the system (24 random bytes, base64url). */
    public const CURRENT_TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32}$/';
    /** What the registration scanner accepts (RegistrationService::extractToken). */
    public const SCANNABLE_TOKEN_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';
    private const LIST_LIMIT = 50;

    /**
     * Reads the QR identity dataset and integrity counts (no writes).
     *
     * @return array<string, mixed>
     */
    public static function snapshot(\PDO $pdo, ?int $eventId = null): array
    {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $events = array_map('intval', $pdo->query('SELECT id FROM events')->fetchAll(\PDO::FETCH_COLUMN));
            $attendees = [];
            foreach ($pdo->query('SELECT id, event_id, attendee_code, full_name, company, department, status FROM attendees')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $attendees[(int) $row['id']] = $row;
            }
            $qrs = $pdo->query('SELECT id, attendee_id, token FROM attendee_qr_codes')->fetchAll(\PDO::FETCH_ASSOC);
            $scanLinks = $pdo->query('SELECT r.attendee_id, q.attendee_id AS qr_attendee_id FROM registration_scans r JOIN attendee_qr_codes q ON q.id = r.qr_code_id')->fetchAll(\PDO::FETCH_ASSOC);
        } finally {
            $pdo->exec('ROLLBACK');
        }

        $inScope = static fn (array $a): bool => $eventId === null || (int) $a['event_id'] === $eventId;
        $records = [];
        $orphans = [];
        $qrPerAttendee = [];
        foreach ($qrs as $qr) {
            $attendee = $attendees[(int) $qr['attendee_id']] ?? null;
            if ($attendee === null) {
                $orphans[] = (int) $qr['id'];
                continue;
            }
            if (!$inScope($attendee)) {
                continue;
            }
            $qrPerAttendee[(int) $attendee['id']] = ($qrPerAttendee[(int) $attendee['id']] ?? 0) + 1;
            $records[] = [
                'event_id' => (int) $attendee['event_id'],
                'attendee_id' => (int) $attendee['id'],
                'attendee_code' => (string) $attendee['attendee_code'],
                'full_name' => $attendee['full_name'],
                'company' => $attendee['company'],
                'department' => $attendee['department'],
                'token' => $qr['token'],
            ];
        }
        $records = self::sortRecords($records);

        $missingQr = [];
        $archivedWithoutQr = 0;
        $attendeesInScope = 0;
        foreach ($attendees as $id => $attendee) {
            if (!$inScope($attendee)) {
                continue;
            }
            $attendeesInScope++;
            if (!isset($qrPerAttendee[$id])) {
                if ($attendee['status'] === 'active') {
                    $missingQr[] = (string) $attendee['attendee_code'];
                } else {
                    $archivedWithoutQr++;
                }
            }
        }

        $tokenCount = [];
        foreach ($records as $r) {
            $key = (string) $r['token'];
            $tokenCount[$key] = ($tokenCount[$key] ?? 0) + 1;
        }
        $codesWhere = static fn (callable $test): array => array_values(array_unique(array_map(
            static fn (array $r): string => $r['attendee_code'],
            array_filter($records, $test)
        )));

        $issues = [
            'missing_qr' => $missingQr,
            'orphan_qr' => $orphans,
            'duplicate_tokens' => $codesWhere(static fn (array $r): bool => $r['token'] !== null && $tokenCount[(string) $r['token']] > 1),
            'duplicate_attendee_qr' => $codesWhere(static fn (array $r): bool => $qrPerAttendee[$r['attendee_id']] > 1),
            'missing_attendee_code' => array_values(array_map(static fn (array $r): int => $r['attendee_id'],
                array_filter($records, static fn (array $r): bool => trim($r['attendee_code']) === ''))),
            'empty_token' => $codesWhere(static fn (array $r): bool => $r['token'] === null || $r['token'] === ''),
            'invalid_token_format' => $codesWhere(static fn (array $r): bool => $r['token'] !== null && $r['token'] !== ''
                && preg_match(self::SCANNABLE_TOKEN_PATTERN, (string) $r['token']) !== 1),
            'event_missing' => $codesWhere(static fn (array $r): bool => !in_array($r['event_id'], $events, true)),
            'scan_qr_mismatch' => count(array_filter($scanLinks, static fn (array $s): bool => (int) $s['attendee_id'] !== (int) $s['qr_attendee_id'])),
        ];
        $warnings = [
            'non_standard_token_length' => $codesWhere(static fn (array $r): bool => is_string($r['token'])
                && preg_match(self::SCANNABLE_TOKEN_PATTERN, $r['token']) === 1 && preg_match(self::CURRENT_TOKEN_PATTERN, $r['token']) !== 1),
            'archived_without_qr' => $archivedWithoutQr,
        ];

        return [
            'event_scope' => $eventId,
            'attendee_count' => $attendeesInScope,
            'attendees_with_qr' => count($qrPerAttendee),
            'qr_count' => count($records),
            'records' => $records,
            'issues' => $issues,
            'warnings' => $warnings,
            'fingerprint' => self::fingerprint($records),
            'identity_fingerprint' => self::identityFingerprint($records),
        ];
    }

    /** Number of integrity problems (each one fails verification). */
    public static function issueCount(array $issues): int
    {
        $n = 0;
        foreach ($issues as $value) {
            $n += is_array($value) ? count($value) : (int) $value;
        }

        return $n;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public static function sortRecords(array $records): array
    {
        usort($records, static function (array $a, array $b): int {
            return ($a['event_id'] <=> $b['event_id'])
                ?: strcmp($a['attendee_code'], $b['attendee_code'])
                ?: ($a['attendee_id'] <=> $b['attendee_id'])
                ?: strcmp((string) $a['token'], (string) $b['token']);
        });

        return $records;
    }

    /** SHA-256 of the canonical full dataset (see class comment). */
    public static function fingerprint(array $records): string
    {
        $hash = hash_init('sha256');
        foreach ($records as $r) {
            hash_update($hash, self::line([$r['event_id'], $r['attendee_id'], $r['attendee_code'], $r['full_name'], $r['company'], $r['department'], $r['token']]));
        }

        return hash_final($hash);
    }

    /** SHA-256 of event_id|attendee_code|token lines (QR identity only). */
    public static function identityFingerprint(array $records): string
    {
        $lines = array_map(static fn (array $r): string => self::line([$r['event_id'], $r['attendee_code'], $r['token']]), $records);
        sort($lines, SORT_STRING);

        return hash('sha256', implode('', $lines));
    }

    /** @param list<mixed> $fields */
    private static function line(array $fields): string
    {
        return implode('|', array_map(static fn (mixed $v): string => $v === null ? '\\N'
            : str_replace(['\\', '|', "\n", "\r"], ['\\\\', '\\|', '\\n', '\\r'], (string) $v), $fields)) . "\n";
    }

    /** @return array<string, mixed> manifest (deterministic apart from generated_at) */
    public static function manifest(array $snapshot, string $database): array
    {
        return [
            'manifest_version' => self::MANIFEST_VERSION,
            'database' => $database,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'event_scope' => $snapshot['event_scope'],
            'attendee_count' => $snapshot['attendee_count'],
            'qr_count' => $snapshot['qr_count'],
            'missing_qr' => count($snapshot['issues']['missing_qr']),
            'duplicate_tokens' => count($snapshot['issues']['duplicate_tokens']),
            'canonical_format' => 'event_id|attendee_id|attendee_code|full_name|company|department|token + LF per record; sorted by event_id, attendee_code (bytes), attendee_id, token; NULL = \\N; escapes \\\\ \\| \\n \\r',
            'fingerprint' => $snapshot['fingerprint'],
            'identity_fingerprint' => $snapshot['identity_fingerprint'],
            'records' => $snapshot['records'],
        ];
    }

    /**
     * Compares a saved manifest with the current snapshot. Identity = event_id +
     * attendee_code + exact token; attendee_id and name/company/cluster changes
     * are reported but do not fail.
     *
     * @return array<string, mixed>
     */
    public static function compare(array $manifest, array $snapshot): array
    {
        $expected = $manifest['records'];
        $actual = $snapshot['records'];
        $key = static fn (array $r): string => $r['event_id'] . "\0" . $r['attendee_code'];
        $expByKey = [];
        foreach ($expected as $r) {
            $expByKey[$key($r)] = $r;
        }
        $actByKey = [];
        $actByToken = [];
        foreach ($actual as $r) {
            $actByKey[$key($r)] = $r;
            if ($r['token'] !== null) {
                $actByToken[(string) $r['token']][] = $r;
            }
        }

        $result = [
            'matching' => [], 'changed_tokens' => [], 'missing' => [], 'unexpected' => [], 'changed_codes' => [],
            'relationship_changes' => [], 'event_mismatch' => [], 'id_changes' => [], 'detail_changes' => [],
        ];
        $accounted = [];
        foreach ($expected as $e) {
            $k = $key($e);
            if (isset($actByKey[$k])) {
                $a = $actByKey[$k];
                $accounted[$k] = true;
                if ($a['token'] === $e['token']) {
                    $result['matching'][] = $e['attendee_code'];
                    if ($a['attendee_id'] !== $e['attendee_id']) {
                        $result['id_changes'][] = $e['attendee_code'];
                    }
                    foreach (['full_name', 'company', 'department'] as $field) {
                        if ($a[$field] !== $e[$field]) {
                            $result['detail_changes'][$field][] = $e['attendee_code'];
                        }
                    }
                } else {
                    $result['changed_tokens'][] = $e['attendee_code'];
                }
                // The expected token now belongs to someone else?
                foreach ($actByToken[(string) $e['token']] ?? [] as $other) {
                    if ($key($other) !== $k) {
                        $result['relationship_changes'][] = $e['attendee_code'] . ' -> ' . $other['attendee_code'];
                    }
                }
                continue;
            }
            $holders = $actByToken[(string) $e['token']] ?? [];
            if ($holders === []) {
                $result['missing'][] = $e['attendee_code'];
                continue;
            }
            foreach ($holders as $h) {
                $accounted[$key($h)] = true;
                if ($h['event_id'] !== $e['event_id']) {
                    $result['event_mismatch'][] = $e['attendee_code'] . " (event {$e['event_id']} -> {$h['event_id']})";
                } else {
                    $result['changed_codes'][] = $e['attendee_code'] . ' -> ' . $h['attendee_code'];
                }
            }
        }
        foreach ($actual as $a) {
            if (!isset($accounted[$key($a)]) && !isset($expByKey[$key($a)])) {
                $result['unexpected'][] = $a['attendee_code'];
            }
        }
        $result['relationship_changes'] = array_values(array_unique($result['relationship_changes']));

        $failures = count($result['changed_tokens']) + count($result['missing']) + count($result['unexpected'])
            + count($result['changed_codes']) + count($result['relationship_changes']) + count($result['event_mismatch'])
            + self::issueCount($snapshot['issues']);
        $result['expected_count'] = count($expected);
        $result['actual_count'] = count($actual);
        $result['pass'] = $failures === 0 && count($result['matching']) === count($expected);

        return $result;
    }

    /** Verifies a manifest's own integrity (records still match its fingerprints). */
    public static function manifestIsIntact(array $manifest): bool
    {
        if (!isset($manifest['records'], $manifest['fingerprint'], $manifest['identity_fingerprint']) || !is_array($manifest['records'])) {
            return false;
        }
        foreach ($manifest['records'] as $r) {
            if (!is_array($r) || !array_key_exists('token', $r) || !isset($r['event_id'], $r['attendee_id'], $r['attendee_code'])) {
                return false;
            }
        }
        $sorted = self::sortRecords($manifest['records']);

        return hash_equals($manifest['fingerprint'], self::fingerprint($sorted))
            && hash_equals($manifest['identity_fingerprint'], self::identityFingerprint($sorted));
    }

    /** Shortened list for console output. @param list<mixed> $items */
    public static function listed(array $items): string
    {
        $shown = array_slice($items, 0, self::LIST_LIMIT);

        return implode(', ', $shown) . (count($items) > self::LIST_LIMIT ? ', … (+' . (count($items) - self::LIST_LIMIT) . ' more)' : '');
    }
}
