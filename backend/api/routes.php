<?php

declare(strict_types=1);

/**
 * API route table. Paths are relative to the API root (/api).
 *
 * CSRF is verified globally for POST/PUT/PATCH/DELETE in index.php.
 * Auth/role checks are declared per route as middleware.
 *
 * Future modules (attendees, imports, qr-codes, registration, randomizers,
 * winners, reports, audit-logs) register their routes here as they are built.
 */

use App\Controllers\AttendeeController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\EventController;
use App\Controllers\HealthController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Models\User;

return static function (Router $router): void {
    $authenticated = AuthMiddleware::authenticated();
    $adminOnly = AuthMiddleware::roles(User::ROLE_ADMIN);

    // System
    $router->get('/health', [HealthController::class, 'show']);

    // Authentication
    $router->get('/auth/session', [AuthController::class, 'session']);
    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/logout', [AuthController::class, 'logout']);
    $router->get('/auth/me', [AuthController::class, 'me'], [$authenticated]);

    // Dashboard
    $router->get('/dashboard/summary', [DashboardController::class, 'summary'], [$authenticated]);

    // Events (any signed-in user can view; only admins can change)
    $router->get('/events', [EventController::class, 'index'], [$authenticated]);
    $router->get('/events/{id}', [EventController::class, 'show'], [$authenticated]);
    $router->post('/events', [EventController::class, 'store'], [$adminOnly]);
    $router->put('/events/{id}', [EventController::class, 'update'], [$adminOnly]);
    $router->patch('/events/{id}/status', [EventController::class, 'updateStatus'], [$adminOnly]);

    // Attendees (scoped to the active event). Import is a 3-step flow:
    // parse (upload) -> preview (validate + duplicates) -> import (commit).
    $router->get('/attendees', [AttendeeController::class, 'index'], [$authenticated]);
    $router->post('/attendees/import/parse', [AttendeeController::class, 'parseImport'], [$adminOnly]);
    $router->post('/attendees/import/preview', [AttendeeController::class, 'previewImport'], [$adminOnly]);
    $router->post('/attendees/import', [AttendeeController::class, 'import'], [$adminOnly]);
    $router->get('/attendees/{id}', [AttendeeController::class, 'show'], [$authenticated]);
    $router->put('/attendees/{id}', [AttendeeController::class, 'update'], [$adminOnly]);
    $router->patch('/attendees/{id}/status', [AttendeeController::class, 'updateStatus'], [$adminOnly]);
};
