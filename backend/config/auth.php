<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // Failed logins allowed per email within the window before it is locked.
    'max_attempts_per_email' => max(1, Env::int('LOGIN_MAX_ATTEMPTS', 5)),
    // Failed logins allowed per IP address (many users may share event Wi-Fi).
    'max_attempts_per_ip' => max(1, Env::int('LOGIN_MAX_ATTEMPTS_PER_IP', 30)),
    // Window and lockout length in minutes.
    'lockout_minutes' => max(1, Env::int('LOGIN_LOCKOUT_MINUTES', 15)),
];
