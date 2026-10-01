<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Models\AuditLog;

/**
 * Records important actions. Audit logging must never break the action being
 * audited, so failures are written to the PHP error log instead of thrown.
 */
final class AuditLogger
{
    public const AUTH_LOGIN = 'auth.login';
    public const AUTH_LOGIN_FAILED = 'auth.login_failed';
    public const AUTH_LOGOUT = 'auth.logout';
    public const EVENT_CREATED = 'event.created';
    public const EVENT_UPDATED = 'event.updated';
    public const EVENT_STATUS_CHANGED = 'event.status_changed';

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function log(
        Request $request,
        string $action,
        ?string $description = null,
        ?int $eventId = null,
        ?array $metadata = null,
        ?int $userId = null
    ): void {
        try {
            AuditLog::create(
                $action,
                $userId ?? AuthService::currentUserId(),
                $eventId,
                $description,
                $metadata,
                $request->ip(),
                $request->userAgent()
            );
        } catch (\Throwable $exception) {
            error_log('[holcim-audit] Failed to write audit log "' . $action . '": ' . $exception->getMessage());
        }
    }
}
