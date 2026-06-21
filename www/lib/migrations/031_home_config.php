<?php
declare(strict_types=1);
/**
 * Migration 031 — UI-managed home configuration.
 *
 * Moves the home/property setup out of the hardcoded config.php keys
 * (config['home']['address'] + config['home']['value_factor']) into a single
 * household-shared DB row so it can be added / edited / removed from the Settings
 * UI (Settings → Home value → home_settings.php).
 *
 *   home_config — one row, id = 1:
 *     address            the property to value (RentCast + net worth); '' = no home
 *     value_factor       co-ownership fraction (0,1] scaling the net-worth contribution
 *     manual_value(+date) a hand-entered current value (works without a RentCast key)
 *     purchase_price/date optional manual purchase basis (net-worth history anchor)
 *     removed_on         set when the home is removed but history is KEPT to that date
 *                        (the "erase" removal clears the row instead)
 *
 * NOT VIS-scoped — one global household value (like alert_settings / spending_plan).
 * Read via home_config() (queries.php, defensive → config fallback if the table is
 * missing); written by home_settings.php.
 *
 *   /usr/local/bin/php83.cli /home/cpuser/www/budget/lib/migrations/031_home_config.php
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS + a row-count-guarded seed that carries the
 * EXISTING config['home'] address/value_factor into the row, so the live primary
 * (full-value address) and the example-instance (value_factor=0.5) deployments keep their
 * current home with no manual re-entry. CLI-only. Run migration-first.
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

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS home_config (
       id                TINYINT UNSIGNED NOT NULL,
       address           VARCHAR(255)  NULL,
       value_factor      DECIMAL(5,4)  NULL,
       manual_value      DECIMAL(15,2) NULL,
       manual_value_date DATE          NULL,
       purchase_price    DECIMAL(15,2) NULL,
       purchase_date     DATE          NULL,
       removed_on        DATE          NULL,
       updated_by        INT           NULL,
       updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       PRIMARY KEY (id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Seed-from-config (only if the row doesn't exist yet) so the existing deployment
// keeps its hardcoded address/factor without re-entering them.
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM home_config")->fetchColumn();
if ($cnt === 0) {
    $home = $CONFIG['home'] ?? [];
    $addr = trim((string)($home['address'] ?? ''));
    $vfRaw = $home['value_factor'] ?? null;
    $vf = ($vfRaw !== null && $vfRaw !== '' && is_numeric($vfRaw) && is_finite((float)$vfRaw))
        ? max(0.0001, min(1.0, (float)$vfRaw)) : null;
    $pdo->prepare("INSERT INTO home_config (id, address, value_factor) VALUES (1, :a, :v)")
        ->execute([':a' => ($addr !== '' ? $addr : null), ':v' => $vf]);
    echo "Migration 031 applied: home_config created + seeded (address="
        . ($addr !== '' ? $addr : '(none)') . ", value_factor=" . ($vf ?? '(none)') . ").\n";
} else {
    echo "Migration 031 applied: home_config ensured (row already present, not reseeded).\n";
}
