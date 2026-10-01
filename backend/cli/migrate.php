<?php

declare(strict_types=1);

/**
 * Database migration runner.
 *
 *   php backend/cli/migrate.php            Apply all pending migrations
 *   php backend/cli/migrate.php --status   List applied / pending migrations
 *
 * Migrations are plain .sql files in database/migrations, applied in filename
 * order and recorded in the `schema_migrations` table so each runs once.
 * Each file contains exactly ONE SQL statement (MySQL DDL auto-commits, so
 * one statement per file keeps failures easy to reason about).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;

$migrationsDirectory = PROJECT_ROOT . '/database/migrations';
$statusOnly = in_array('--status', $argv, true);

try {
    $pdo = Database::connection();
} catch (\PDOException $exception) {
    fwrite(STDERR, "Could not connect to the database: {$exception->getMessage()}\n");
    fwrite(STDERR, "Check DB_* values in backend/.env and that MySQL is running.\n");
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration VARCHAR(255) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_schema_migrations_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob($migrationsDirectory . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    echo "No migration files found in {$migrationsDirectory}\n";
    exit(0);
}

if ($statusOnly) {
    foreach ($files as $file) {
        $name = basename($file);
        printf("  [%s] %s\n", in_array($name, $applied, true) ? 'applied' : 'pending', $name);
    }
    exit(0);
}

$record = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
$ranCount = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "Skipping empty migration {$name}\n");
        continue;
    }

    echo "Migrating: {$name}\n";
    try {
        $pdo->exec($sql);
        $record->execute(['migration' => $name]);
    } catch (\PDOException $exception) {
        fwrite(STDERR, "FAILED: {$name}\n{$exception->getMessage()}\n");
        exit(1);
    }
    $ranCount++;
}

echo $ranCount === 0 ? "Nothing to migrate. Database is up to date.\n" : "Done. Applied {$ranCount} migration(s).\n";
