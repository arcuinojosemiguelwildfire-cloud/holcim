<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Csrf;
use App\Utils\Validator;

final class AuthController
{
    /**
     * GET /auth/session - always 200. Tells the SPA whether it is logged in
     * and hands it the CSRF token required for every state-changing request.
     */
    public static function session(Request $request): Response
    {
        $user = AuthService::currentUser();

        return Response::success([
            'authenticated' => $user !== null,
            'user' => $user !== null ? User::toPublic($user) : null,
            'csrfToken' => Csrf::token(),
        ]);
    }

    /**
     * POST /auth/login {login | email, password}
     * "login" accepts an email address or a username (Phase 8 scanner
     * operators); "email" is still accepted for older clients.
     */
    public static function login(Request $request): Response
    {
        $body = $request->body();
        if (!isset($body['login']) && isset($body['email'])) {
            $body['login'] = $body['email'];
        }
        $input = Validator::make($body)
            ->string('login', required: true, max: 190, label: 'Email or username')
            ->string('password', required: true, max: 1024)
            ->validate();

        // Use the raw password (validator trims); passwords may contain spaces.
        $password = (string) $request->input('password');
        $user = AuthService::attempt($request, $input['login'], $password);

        return Response::success([
            'user' => $user,
            'csrfToken' => Csrf::token(),
        ], message: 'Logged in successfully.');
    }

    /** POST /auth/logout */
    public static function logout(Request $request): Response
    {
        AuthService::logout($request);

        return Response::success(null, message: 'Logged out.');
    }

    /** GET /auth/me - protected example: 401 when not logged in. */
    public static function me(Request $request): Response
    {
        $user = AuthService::currentUser();

        return Response::success(['user' => User::toPublic($user ?? [])]);
    }
}
