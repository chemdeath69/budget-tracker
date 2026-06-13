<?php
declare(strict_types=1);
/**
 * Migration 025 — retirement_settings.return_volatility (TODO2 #36, Monte Carlo).
 *
 * Adds the expected return VOLATILITY (annual std-dev of returns, e.g. 0.1300 = 13%) that
 * drives the retirement Monte Carlo simulation's spread. NULL = use the bundled default
 * (RET_DEFAULT_VOLATILITY in lib/retirement.php). The household's ~quarter of account
 * history is far too short to derive a volatility honestly, so it's an owner-set assumption
 * alongside the existing growth rate — same override/default shape as growth_rate_override.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/025_retirement_volatility.php
 *
 * Idempotent: information_schema-guarded ADD COLUMN (MySQL 8 has no ADD COLUMN IF NOT EXISTS).
 * CLI-only. Run migration-first — q_retirement_settings() selects this column, so the read
 * would error (or fall to its defensive default) if it deployed before the column exists.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$CONFIG = require __DIR__ . '/../config.php';
$d = $CONFIG['db'];
$dsn = !empty($d['socket'])
    ? "mysql:unix_socket={$d['socket']};dbname={$d['name']};charset=utf8mb4"
    : "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4";
$pdo = new PDO($dsn, $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$exists = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'retirement_settings'
       AND COLUMN_NAME = 'return_volatility'"
)->fetchColumn();

if ($exists === 0) {
    $pdo->exec(
        "ALTER TABLE retirement_settings
         ADD COLUMN return_volatility DECIMAL(6,4) NULL AFTER growth_default"
    );
    echo "Migration 025 applied: retirement_settings.return_volatility added.\n";
} else {
    echo "Migration 025: retirement_settings.return_volatility already present — no change.\n";
}
