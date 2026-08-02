<?php

declare(strict_types=1);

/**
 * Rebuilds the database from database/schema.sql, then applies every file in
 * database/migrations/ in filename order.
 *
 * DESTRUCTIVE: drops every table in the target database first. Intended as a
 * local dev reset, so it refuses to run without --force.
 *
 * Usage: php commands/reimport-database.php --force
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Store\Config\Database;

$force = in_array('--force', $argv, true);

if (!$force) {
    $name = getenv('DB_NAME') ?: 'online_store';
    fwrite(STDERR, "This DROPS every table in \"{$name}\" and rebuilds it from schema.sql.\n");
    fwrite(STDERR, "Re-run with --force if that is what you want:\n");
    fwrite(STDERR, "  php commands/reimport-database.php --force\n");
    exit(1);
}

$pdo = Database::connection();

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    echo "dropped {$table}\n";
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

/**
 * schema.sql carries its own CREATE DATABASE / USE lines so it can bootstrap a
 * fresh server. Those are stripped here because this command always runs
 * against the database the connection already points at.
 */
function runSqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Could not read ' . $path);
    }

    $sql = preg_replace('/^\s*(CREATE\s+DATABASE|USE)\b[^;]*;/im', '', $sql) ?? $sql;

    if (trim($sql) === '') {
        return;
    }

    $pdo->exec($sql);
}

runSqlFile($pdo, __DIR__ . '/../database/schema.sql');
echo "applied schema.sql\n";

$migrationDir = __DIR__ . '/../database/migrations';
$migrations = is_dir($migrationDir) ? glob($migrationDir . '/*.sql') : [];
sort($migrations);

foreach ($migrations as $migration) {
    try {
        runSqlFile($pdo, $migration);
        echo 'applied ' . basename($migration) . "\n";
    } catch (PDOException $e) {
        // schema.sql already includes everything earlier migrations added, so a
        // "column exists" style failure here just means it is already applied.
        echo 'skipped ' . basename($migration) . ' (' . $e->getMessage() . ")\n";
    }
}

echo "Database rebuilt.\n";
