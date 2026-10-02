<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;
use App\Services\ScannerOperatorService;
use App\Services\SystemResetService;
use App\Utils\Validator;

/** Settings (admin only). Phase 8: Scanner Operators. */
final class SettingsController
{
    /** GET /settings/scanner-operators */
    public static function scannerOperators(Request $request): Response
    {
        return Response::success(ScannerOperatorService::list());
    }

    /** POST /settings/scanner-operators {name, username, password, password_confirmation, status} */
    public static function createScannerOperator(Request $request): Response
    {
        $data = Validator::make($request->body())
            ->string('name', required: true, max: 150, label: 'Display name')
            ->string('username', required: true, max: 60, min: 3, label: 'Username')
            ->in('status', [User::STATUS_ACTIVE, User::STATUS_INACTIVE], required: true)
            ->validate();
        $data['username'] = strtolower($data['username']);
        if (!preg_match(User::USERNAME_PATTERN, $data['username'])) {
            throw HttpException::validation(['username' => ['Use 3-60 lower-case letters, numbers, dots, underscores or hyphens.']]);
        }
        $password = ScannerOperatorService::validatePassword($request->input('password'), $request->input('password_confirmation'));

        return Response::created(['operator' => ScannerOperatorService::create($request, $data, $password)], 'Scanner operator created.');
    }

    /** PATCH /settings/scanner-operators/{id} {status} */
    public static function updateScannerOperator(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Scanner operator');
        $data = Validator::make($request->body())
            ->in('status', [User::STATUS_ACTIVE, User::STATUS_INACTIVE], required: true)
            ->validate();

        return Response::success(
            ['operator' => ScannerOperatorService::setStatus($request, $id, $data['status'])],
            message: $data['status'] === User::STATUS_ACTIVE ? 'Scanner operator enabled.' : 'Scanner operator disabled.'
        );
    }

    /** POST /settings/scanner-operators/{id}/password {password, password_confirmation} */
    public static function resetScannerOperatorPassword(Request $request): Response
    {
        $id = Validator::id($request->param('id'), 'Scanner operator');
        $password = ScannerOperatorService::validatePassword($request->input('password'), $request->input('password_confirmation'));
        ScannerOperatorService::resetPassword($request, $id, $password);

        return Response::success(null, message: 'Password reset.');
    }

    /** GET /settings/system-reset - what a reset would remove (counts only) */
    public static function resetSummary(Request $request): Response
    {
        return Response::success(['counts' => SystemResetService::counts(), 'confirmationPhrase' => SystemResetService::CONFIRMATION_PHRASE]);
    }

    /** POST /settings/system-reset {confirmation: "RESET EVENT DATA", password} - admin only, destructive */
    public static function reset(Request $request): Response
    {
        $confirmation = $request->input('confirmation');
        $password = $request->input('password');
        $result = SystemResetService::reset(
            $request,
            is_string($confirmation) ? $confirmation : '',
            is_string($password) ? $password : ''
        );

        return Response::success($result, message: 'System reset completed. All event/test data was removed. Admin account was preserved.');
    }
}
