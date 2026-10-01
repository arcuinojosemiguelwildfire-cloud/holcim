<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for `import_batches` (one row per completed import).
 */
final class ImportBatch
{
    public const TYPE_ATTENDEES = 'attendees';

    /**
     * @param array<string, mixed> $columnMapping
     * @param array<string, mixed> $errorSummary
     */
    public static function createCompleted(
        int $eventId,
        string $type,
        string $filename,
        int $totalRows,
        int $successfulRows,
        int $failedRows,
        array $columnMapping,
        array $errorSummary,
        ?int $userId
    ): int {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $statement = Database::connection()->prepare(
            'INSERT INTO import_batches
                (event_id, import_type, original_filename, status, total_rows, successful_rows, failed_rows,
                 column_mapping, error_summary, imported_by, completed_at)
             VALUES (:event_id, :type, :filename, :status, :total, :successful, :failed,
                 :mapping, :errors, :user_id, NOW())'
        );
        $statement->execute([
            'event_id' => $eventId,
            'type' => $type,
            'filename' => $filename,
            'status' => 'completed',
            'total' => $totalRows,
            'successful' => $successfulRows,
            'failed' => $failedRows,
            'mapping' => json_encode($columnMapping, $flags),
            'errors' => json_encode($errorSummary, $flags),
            'user_id' => $userId,
        ]);

        return (int) Database::connection()->lastInsertId();
    }
}
