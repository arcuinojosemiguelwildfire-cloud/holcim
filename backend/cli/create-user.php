<?php

declare(strict_types=1);

/**
 * Creates a user account from the command line. This is how the FIRST admin
 * is created - there are no default or hard-coded credentials.
 *
 *   php backend/cli/create-user.php
 *       Interactive: prompts for name, email and password (hidden input).
 *
 *   php backend/cli/create-user.php --name="Jane Cruz" --email=jane@example.com [--role=admin]
 *       Prompts only for the password.
 *
 *   echo "$PASSWORD" | php backend/cli/create-user.php --name=... --email=... --password-stdin
 *       Non-interactive (scripts/CI). Never pass a password as an argument:
 *       it would be saved in shell history.
 *
 * Roles: admin (default), registration_staff, event_operator
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Models\User;

const MIN_PASSWORD_LENGTH = 10;

$options = getopt('', ['name:', 'email:', 'role:', 'password-stdin', 'help']);

if (isset($options['help'])) {
    echo "Usage: php backend/cli/create-user.php [--name=NAME] [--email=EMAIL] [--role=ROLE] [--password-stdin]\n";
    echo 'Roles: ' . implode(', ', User::ROLES) . "\n";
    exit(0);
}

function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

function prompt(string $label): string
{
    echo $label;
    $line = fgets(STDIN);

    return $line === false ? '' : trim($line);
}

function promptHidden(string $label): string
{
    $canHide = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    echo $label;
    if ($canHide) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($canHide) {
        shell_exec('stty echo');
        echo "\n";
    }

    return $line === false ? '' : rtrim($line, "\r\n");
}

$name = trim((string) ($options['name'] ?? '')) ?: prompt('Full name: ');
$email = strtolower(trim((string) ($options['email'] ?? '')) ?: prompt('Email: '));
$role = (string) ($options['role'] ?? User::ROLE_ADMIN);

if ($name === '' || mb_strlen($name) > 150) {
    fail('Name is required (max 150 characters).');
}
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
    fail('A valid email address is required.');
}
if (!in_array($role, User::ROLES, true)) {
    fail('Role must be one of: ' . implode(', ', User::ROLES));
}

if (isset($options['password-stdin'])) {
    $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
} else {
    $password = promptHidden('Password (min ' . MIN_PASSWORD_LENGTH . ' characters): ');
    if ($password !== promptHidden('Confirm password: ')) {
        fail('Passwords do not match.');
    }
}

if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
    fail('Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.');
}

try {
    if (User::findByEmailWithPassword($email) !== null) {
        fail("A user with email {$email} already exists.");
    }

    $id = User::create($name, $email, password_hash($password, PASSWORD_DEFAULT), $role);
} catch (\PDOException $exception) {
    fail('Database error: ' . $exception->getMessage() . "\nHave you run php backend/cli/migrate.php?");
}

echo "Created {$role} user #{$id}: {$name} <{$email}>\n";
