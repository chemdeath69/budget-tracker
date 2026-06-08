<?php
declare(strict_types=1);
/**
 * Migration 008 — add a third account visibility state: 'hidden'.
 *
 * Widens `accounts.visibility` from ENUM('shared','private') to
 * ENUM('shared','private','hidden'). A 'hidden' account is registered NOWHERE in
 * the app except its owner's settings page: it is excluded from every read (the VIS
 * clause in queries.php now drops it) AND from the two aggregations that ignore
 * visibility — the household net-worth snapshot (lib/sync.php write_networth_snapshot)
 * and the per-account balance history (cron/sync.php). Plaid keeps syncing it silently
 * (balance/transactions stay current in the DB), it's just never displayed or counted.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/008_hidden_visibility.php
 *
 * Idempotent: re-reads the live COLUMN_TYPE and only ALTERs when 'hidden' is absent
 * (MySQL 8 has no conditional enum change). CLI-only.
 *
 * ORDERING: run this BEFORE deploying the code — the API can't store 'hidden' until
 * the enum accepts it (the `<> 'hidden'` reads are harmless on the old enum).
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

$colType = (string)$pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'accounts'
       AND column_name = 'visibility'"
)->fetchColumn();

if (stripos($colType, "'hidden'") === false) {
    $pdo->exec(
        "ALTER TABLE accounts MODIFY COLUMN visibility
         ENUM('shared','private','hidden') NOT NULL DEFAULT 'shared'"
    );
    echo "Migration 008 applied: accounts.visibility now ENUM('shared','private','hidden').\n";
} else {
    echo "Migration 008 skipped: accounts.visibility already includes 'hidden'.\n";
}
