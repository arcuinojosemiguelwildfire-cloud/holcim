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
use App\Controllers\EventDayController;
use App\Controllers\HealthController;
use App\Controllers\MajorFormController;
use App\Controllers\MajorEligibilityController;
use App\Controllers\QrCodeController;
use App\Controllers\RandomizerController;
use App\Controllers\RegistrationController;
use App\Controllers\ReportController;
use App\Controllers\SettingsController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Models\User;

return static function (Router $router): void {
    $authenticated = AuthMiddleware::authenticated();
    $adminOnly = AuthMiddleware::roles(User::ROLE_ADMIN);
    // Phase 8 role groups. scanner_operator only gets scanner endpoints.
    $staff = AuthMiddleware::roles(User::ROLE_ADMIN, User::ROLE_REGISTRATION_STAFF, User::ROLE_EVENT_OPERATOR);
    $scanners = AuthMiddleware::roles(User::ROLE_ADMIN, User::ROLE_REGISTRATION_STAFF, User::ROLE_SCANNER_OPERATOR);

    // System
    $router->get('/health', [HealthController::class, 'show']);

    // Authentication
    $router->get('/auth/session', [AuthController::class, 'session']);
    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/logout', [AuthController::class, 'logout']);
    $router->get('/auth/me', [AuthController::class, 'me'], [$authenticated]);

    // Dashboard (scanner operators use the scanner dashboard: /registration/summary)
    $router->get('/dashboard/summary', [DashboardController::class, 'summary'], [$staff]);

    // Current event + Event Day: every signed-in role (shown in the top bar).
    $router->get('/event-days/current', [EventDayController::class, 'current'], [$authenticated]);

    // Events (staff can view; only admins can change)
    $router->get('/events', [EventController::class, 'index'], [$staff]);
    $router->get('/events/{id}', [EventController::class, 'show'], [$staff]);
    $router->post('/events', [EventController::class, 'store'], [$adminOnly]);
    $router->put('/events/{id}', [EventController::class, 'update'], [$adminOnly]);
    $router->patch('/events/{id}/status', [EventController::class, 'updateStatus'], [$adminOnly]);

    // Event days (Phase 8): admin only. Activating a day makes it the current day.
    $router->get('/events/{id}/days', [EventDayController::class, 'index'], [$adminOnly]);
    $router->post('/events/{id}/days', [EventDayController::class, 'store'], [$adminOnly]);
    $router->put('/event-days/{id}', [EventDayController::class, 'update'], [$adminOnly]);
    $router->patch('/event-days/{id}/activate', [EventDayController::class, 'activate'], [$adminOnly]);

    // Attendees (scoped to the active event). Import is a 3-step flow:
    // parse (upload) -> preview (validate + duplicates) -> import (commit).
    $router->get('/attendees', [AttendeeController::class, 'index'], [$staff]);
    $router->post('/attendees/import/parse', [AttendeeController::class, 'parseImport'], [$adminOnly]);
    $router->post('/attendees/import/preview', [AttendeeController::class, 'previewImport'], [$adminOnly]);
    $router->post('/attendees/import', [AttendeeController::class, 'import'], [$adminOnly]);
    $router->get('/attendees/{id}', [AttendeeController::class, 'show'], [$staff]);
    $router->put('/attendees/{id}', [AttendeeController::class, 'update'], [$adminOnly]);
    $router->patch('/attendees/{id}/status', [AttendeeController::class, 'updateStatus'], [$adminOnly]);

    // Attendee QR codes (active event). Viewing/printing: admin + registration
    // staff. Generating/regenerating: admin only.
    $qrViewers = AuthMiddleware::roles(User::ROLE_ADMIN, User::ROLE_REGISTRATION_STAFF);
    $router->get('/qr-codes/summary', [QrCodeController::class, 'summary'], [$qrViewers]);
    $router->get('/qr-codes', [QrCodeController::class, 'index'], [$qrViewers]);
    $router->get('/qr-codes/print', [QrCodeController::class, 'print'], [$qrViewers]);
    $router->post('/qr-codes/generate-missing', [QrCodeController::class, 'generateMissing'], [$adminOnly]);
    $router->get('/attendees/{id}/qr', [QrCodeController::class, 'show'], [$qrViewers]);
    $router->post('/attendees/{id}/qr', [QrCodeController::class, 'generate'], [$adminOnly]);
    $router->post('/attendees/{id}/qr/regenerate', [QrCodeController::class, 'regenerate'], [$adminOnly]);

    // Registration (QR check-in) for the ACTIVE DAY: admin, registration staff, scanner operator.
    $router->get('/registration/summary', [RegistrationController::class, 'summary'], [$scanners]);
    $router->post('/registration/scan', [RegistrationController::class, 'scan'], [$scanners]);
    $router->get('/registration/scans', [RegistrationController::class, 'scans'], [$scanners]);
    // Read-only attendee status lookup: every role.
    $router->get('/registration/attendees', [RegistrationController::class, 'attendees'], [$authenticated]);

    // Randomizers (active event): admin + event operator.
    $randomizerOperators = AuthMiddleware::roles(User::ROLE_ADMIN, User::ROLE_EVENT_OPERATOR);
    $router->get('/randomizers/minor', [RandomizerController::class, 'minorSummary'], [$randomizerOperators]);
    $router->post('/randomizers/minor/draw', [RandomizerController::class, 'minorDraw'], [$randomizerOperators]);
    $router->get('/randomizers/major', [RandomizerController::class, 'majorSummary'], [$randomizerOperators]);
    $router->post('/randomizers/major/draw', [RandomizerController::class, 'majorDraw'], [$randomizerOperators]);

    // Manual participants for TODAY's pools (Phase 8): admin + event operator.
    $router->get('/randomizers/minor/candidates', [RandomizerController::class, 'minorCandidates'], [$randomizerOperators]);
    $router->get('/randomizers/minor/participants', [RandomizerController::class, 'minorParticipants'], [$randomizerOperators]);
    $router->post('/randomizers/minor/participants', [RandomizerController::class, 'addMinorParticipant'], [$randomizerOperators]);
    $router->get('/randomizers/major/candidates', [RandomizerController::class, 'majorCandidates'], [$randomizerOperators]);
    $router->get('/randomizers/major/participants', [RandomizerController::class, 'majorParticipants'], [$randomizerOperators]);
    $router->post('/randomizers/major/participants', [RandomizerController::class, 'addMajorParticipant'], [$randomizerOperators]);

    // Major eligibility (imported form responses). View: admin + event operator.
    // Import: admin only.
    $router->get('/major-eligibility', [MajorEligibilityController::class, 'index'], [$randomizerOperators]);
    $router->get('/major-eligibility/imports', [MajorEligibilityController::class, 'imports'], [$randomizerOperators]);
    $router->post('/major-eligibility/import/parse', [MajorEligibilityController::class, 'parseImport'], [$adminOnly]);
    $router->post('/major-eligibility/import/preview', [MajorEligibilityController::class, 'previewImport'], [$adminOnly]);
    $router->post('/major-eligibility/import', [MajorEligibilityController::class, 'import'], [$adminOnly]);

    // Draw voiding (Phase 7): admin + event operator. Never deletes the draw.
    $router->post('/randomizers/draws/{id}/void', [RandomizerController::class, 'voidDraw'], [$randomizerOperators]);

    // Major QR (Phase 7): public redirect to MAJOR_FORM_URL; info for the display page.
    $router->get('/major-form', [MajorFormController::class, 'redirect']);
    $router->get('/major-form/info', [MajorFormController::class, 'info'], [$randomizerOperators]);

    // CSV reports for the active event (Phase 7), ?scope=day|all (Phase 8): admin only.
    $router->get('/reports/registration.csv', [ReportController::class, 'registration'], [$adminOnly]);
    $router->get('/reports/major-eligibility.csv', [ReportController::class, 'majorEligibility'], [$adminOnly]);
    $router->get('/reports/draws.csv', [ReportController::class, 'draws'], [$adminOnly]);

    // Settings > Scanner Operators (Phase 8): admin only.
    $router->get('/settings/scanner-operators', [SettingsController::class, 'scannerOperators'], [$adminOnly]);
    $router->post('/settings/scanner-operators', [SettingsController::class, 'createScannerOperator'], [$adminOnly]);
    $router->patch('/settings/scanner-operators/{id}', [SettingsController::class, 'updateScannerOperator'], [$adminOnly]);
    $router->post('/settings/scanner-operators/{id}/password', [SettingsController::class, 'resetScannerOperatorPassword'], [$adminOnly]);
};
