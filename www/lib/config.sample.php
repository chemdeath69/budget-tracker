<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored and must NEVER be committed.
 *
 * DB: this host runs MySQL 5 AND MySQL 8. Our DB is on MySQL 8 — connect via the
 * unix socket /tmp/mysql8.sock (preferred) or TCP 127.0.0.1:3308. The default
 * localhost/3306 is the WRONG server. Note the MySQL username is the SHORT name
 * (e.g. "budget"), not the prefixed database name ("cpuser_budget").
 */
return [
    'db' => [
        'socket'  => '/tmp/mysql8.sock',   // preferred on this host (MySQL 8)
        'host'    => '127.0.0.1',          // fallback if socket unset
        'port'    => 3308,
        'name'    => 'cpuser_budget',
        'user'    => 'budget',             // SHORT name, not cpuser_budget
        'pass'    => 'CHANGE_ME_dbpass',
        'charset' => 'utf8mb4',
    ],

    'google' => [
        'client_id'     => 'CHANGE_ME.apps.googleusercontent.com',
        'client_secret' => 'CHANGE_ME',
        'redirect_uri'  => 'https://budget.example.com/oauth-callback.php',
    ],

    'plaid' => [
        'env'         => 'production',      // 'production' | 'sandbox'
        'client_id'   => 'CHANGE_ME',
        'secret'      => 'CHANGE_ME_production_secret',
        'webhook_url' => 'https://budget.example.com/webhook.php',
        // Initial history pull (days) on first sync of each Item.
        'days_requested' => 730,
    ],

    // Only these Google accounts may sign in. Everyone else is rejected.
    'allowed_emails' => [
        'you@example.com',
        'partner@example.com',
    ],

    // base64 of 32 random bytes — encrypts Plaid access_tokens at rest.
    // Generate with: php -r "echo base64_encode(random_bytes(32));"
    // LOSING THIS = must re-link all banks.
    'encryption_key' => 'CHANGE_ME_base64_32_bytes',

    // Random long string. Generate: php -r "echo bin2hex(random_bytes(32));"
    'session_secret' => 'CHANGE_ME_random_64_hex_chars',

    // Email alerts.
    'alerts' => [
        'recipients'        => ['you@example.com'],
        'large_tx_threshold' => 200.0,    // USD; outflow above this triggers an email
        'from'              => 'budget@example.com',
    ],
];
