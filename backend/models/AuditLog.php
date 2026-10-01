<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data access for the append-only `audit_logs` table.
 */
final class AuditLog
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function create(
        string $action,
        ?int $userId = null,
        ?int $eventId = null,
        ?string $description = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        $statement = Database::connection()->prepare(
            'INSERT INTO audit_logs (user_id, event_id, action, description, metadata, ip_address, user_agent)
             VALUES (:user_id, :event_id, :action, :description, :metadata, :ip_address, :user_agent)'
        );
        $statement->execute([
            'user_id' => $userId,
            'event_id' => $eventId,
            'action' => $action,
            'description' => $description !== null ? mb_substr($description, 0, 500) : null,
            'metadata' => $metadata !== null
                ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
            'ip_address' => $ipAddress !== null && $ipAddress !== '' ? substr($ipAddress, 0, 45) : null,
            'user_agent' => $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
        ]);

        return (int) Database::connection()->lastInsertId();
    }
}
