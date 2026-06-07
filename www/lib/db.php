<?php
declare(strict_types=1);

/**
 * Returns a shared PDO connection (lazy singleton).
 *
 * This host runs TWO MySQL servers. Our DB lives on MySQL 8, reachable via the
 * unix socket /tmp/mysql8.sock (preferred) or TCP 127.0.0.1:3308 — NOT the
 * default localhost/3306 (that's the old MySQL 5 server). Configure via
 * $CONFIG['db']['socket'] (preferred) or host+port.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $CONFIG;
    $c = $CONFIG['db'];

    if (!empty($c['socket'])) {
        $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $c['socket'], $c['name'], $c['charset']);
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], (int)($c['port'] ?? 3306), $c['name'], $c['charset']);
    }

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $ex) {
        error_log('DB connect failed: ' . $ex->getMessage());
        http_response_code(500);
        exit('Database connection failed. Check lib/config.php.');
    }

    return $pdo;
}
