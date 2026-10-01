<?php

declare(strict_types=1);

/**
 * Shared bootstrap for both the HTTP API (index.php) and CLI scripts.
 * Sets up autoloading, environment, configuration and time zone.
 */

const BACKEND_ROOT = __DIR__;
define('PROJECT_ROOT', dirname(__DIR__));

/*
 * PSR-4 style autoloader without Composer.
 * App\Core\Router        -> backend/core/Router.php
 * App\Controllers\Foo    -> backend/controllers/Foo.php
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $segments = explode('\\', substr($class, strlen($prefix)));
    $className = array_pop($segments);
    $directory = strtolower(implode('/', $segments));
    $file = BACKEND_ROOT . '/' . ($directory !== '' ? $directory . '/' : '') . $className . '.php';

    if (is_file($file)) {
        require $file;
    }
});

App\Core\Env::load(BACKEND_ROOT . '/.env');
App\Core\Config::loadDirectory(BACKEND_ROOT . '/config');

date_default_timezone_set((string) App\Core\Config::get('app.timezone', 'UTC'));

// Errors are logged, never printed into JSON responses.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
