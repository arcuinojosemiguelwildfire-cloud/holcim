<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\User;
use App\Services\AuthService;

/**
 * Route middleware factories:
 *
 *   $router->get('/auth/me', $handler, [AuthMiddleware::authenticated()]);
 *   $router->post('/events', $handler, [AuthMiddleware::roles(User::ROLE_ADMIN)]);
 */
final class AuthMiddleware
{
    public static function authenticated(): callable
    {
        return static function (Request $request): void {
            if (AuthService::currentUser() === null) {
                throw HttpException::unauthorized();
            }
        };
    }

    public static function roles(string ...$roles): callable
    {
        foreach ($roles as $role) {
            if (!in_array($role, User::ROLES, true)) {
                throw new \InvalidArgumentException("Unknown role: {$role}");
            }
        }

        return static function (Request $request) use ($roles): void {
            $user = AuthService::currentUser();
            if ($user === null) {
                throw HttpException::unauthorized();
            }
            if (!in_array($user['role'], $roles, true)) {
                throw HttpException::forbidden();
            }
        };
    }
}
