<?php
declare(strict_types=1);
/**
 * Migration 032 — users.role / status / added_by (DB-backed allowlist + admin role).
 *
 * Moves the access allowlist out of config['allowed_emails'] (static, dev-edit-only)
 * into the `users` table so it can be managed from a Settings page (add / remove /
 * promote / demote), and introduces an admin role:
 *
 *   role   ENUM('admin','member')   — admin can manage users + run Factory Reset.
 *   status ENUM('active','disabled')— 'disabled' = access revoked (data kept).
 *   added_by INT UNSIGNED NULL      — who invited this user (audit; NULL = bootstrap/self).
 *
 * A row in `users` IS the allowlist entry: a `status='active'` row may sign in. A
 * pre-authorized person who has never logged in is a row with a NULL google_sub.
 *
 * Runtime (lib/auth.php) layers two safety nets on top of this table:
 *   - BOOTSTRAP: if `users` is empty, the first successful Google login is allowed
 *     and made admin (the fresh-install path).
 *   - BREAK-GLASS: config['allowed_emails'] are ALWAYS allowed + always admin at the
 *     session level, so an admin can never be locked out via the UI.
 *
 * SEED (this migration): so an existing deployment isn't locked out, every
 * config['allowed_emails'] address is ensured present + set role='admin', status='active'.
 * Any other already-existing user stays role='member', status='active' (the defaults).
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/example-instance/lib/migrations/032_user_roles.php
 *
 * Idempotent: information_schema-guarded ADD COLUMN (MySQL 8 has no ADD COLUMN IF NOT
 * EXISTS); the seed is INSERT IGNORE + UPDATE. CLI-only. Run migration-first — auth.php
 * reads users.role/status, so the code would 500 before the columns exist.
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

/** True if users.$col already exists. */
$hasCol = function (string $col) use ($pdo): bool {
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = :c"
    );
    $st->execute([':c' => $col]);
    return (bool)$st->fetchColumn();
};

if ($hasCol('role')) {
    echo "Migration 032: users.role already present — skipped.\n";
} else {
    $pdo->exec(
        "ALTER TABLE users
           ADD COLUMN role ENUM('admin','member') NOT NULL DEFAULT 'member' AFTER name"
    );
    echo "Migration 032 applied: users.role added.\n";
}

if ($hasCol('status')) {
    echo "Migration 032: users.status already present — skipped.\n";
} else {
    $pdo->exec(
        "ALTER TABLE users
           ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active' AFTER role"
    );
    echo "Migration 032 applied: users.status added.\n";
}

if ($hasCol('added_by')) {
    echo "Migration 032: users.added_by already present — skipped.\n";
} else {
    $pdo->exec(
        "ALTER TABLE users
           ADD COLUMN added_by INT UNSIGNED NULL AFTER status"
    );
    echo "Migration 032 applied: users.added_by added.\n";
}

// Seed the allowlist from config['allowed_emails'] so an existing deployment keeps
// its access + gets at least one admin. Idempotent: ensures the row exists, then
// grants admin/active. (A fresh install with no users + no config emails seeds
// nothing — the first login bootstraps itself to admin.)
$allowed = array_values(array_unique(array_map(
    'strtolower',
    array_map('trim', (array)($CONFIG['allowed_emails'] ?? []))
)));
$ins = $pdo->prepare('INSERT IGNORE INTO users (email, role, status) VALUES (:e, \'admin\', \'active\')');
$upd = $pdo->prepare("UPDATE users SET role = 'admin', status = 'active' WHERE email = :e");
$seeded = 0;
foreach ($allowed as $email) {
    if ($email === '') continue;
    $ins->execute([':e' => $email]);
    $upd->execute([':e' => $email]);
    $seeded++;
}
echo "Migration 032: seeded {$seeded} config allowed_email(s) as admin/active.\n";
