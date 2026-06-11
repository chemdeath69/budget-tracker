<?php
declare(strict_types=1);
/**
 * Migration 020 — budgets.rollover (TODO #11b).
 *
 * Opt-in per-budget "carry unspent forward". When a budget row has rollover=1,
 * q_budgets() (lib/queries.php) computes a carryover bucket at READ time:
 *
 *     available = monthly_limit + Σ_completed_months max(0, runningCarry + (limit − spent))
 *
 * accumulated from the budget's creation MONTH (anchored on the existing
 * `budgets.created_at`) up to last month — the running total is floored at $0 so an
 * overspent month draws the bucket down but never makes it negative. A non-rollover
 * budget is byte-for-byte unchanged (available = monthly_limit, carryover = 0).
 *
 * NO backfill — the flag drives a pure read-time derive, so flipping it on/off takes
 * effect on the next page load.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/020_budget_rollover.php
 *
 * Idempotent: information_schema-guarded ADD COLUMN (MySQL 8 has no ADD COLUMN IF NOT
 * EXISTS). Also defensively ensures `created_at` exists (the carry anchor) — it is part
 * of the original budgets table, so the guard normally skips it. CLI-only. Run
 * migration-first — q_budgets reads `rollover`, so the code would 500 before the column
 * exists.
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

/** True if budgets.$col already exists. */
$hasCol = function (string $col) use ($pdo): bool {
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budgets' AND COLUMN_NAME = :c"
    );
    $st->execute([':c' => $col]);
    return (bool)$st->fetchColumn();
};

if ($hasCol('rollover')) {
    echo "Migration 020: budgets.rollover already present — skipped.\n";
} else {
    $pdo->exec(
        "ALTER TABLE budgets
           ADD COLUMN rollover TINYINT(1) NOT NULL DEFAULT 0 AFTER monthly_limit"
    );
    echo "Migration 020 applied: budgets.rollover added.\n";
}

// Defensive — the carry anchor. Part of the original schema, so this normally no-ops.
if (!$hasCol('created_at')) {
    $pdo->exec(
        "ALTER TABLE budgets
           ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
    );
    echo "Migration 020: budgets.created_at added (was missing).\n";
}
