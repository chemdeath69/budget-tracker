<?php
declare(strict_types=1);

/**
 * Manual (non-Plaid) account-type registry.
 *
 * Each entry describes a kind of account whose data the household updates by
 * uploading documents (instead of an automatic Plaid feed). To add a new type:
 *   1. add an entry here,
 *   2. create lib/manual/<handler>.php providing <handler>_parse(string $text)
 *      and <handler>_ingest(PDO,$account,$parsed,$docId) — see webull.php,
 *   3. that's it: the generic ingest pipeline + UI pick it up automatically.
 */

function manual_types(): array
{
    return [
        'webull' => [
            'label'           => 'Webull',
            'institution'     => 'Webull Financial',
            'account_type'    => 'investment',   // accounts.type → asset, shows Holdings
            'account_subtype' => 'brokerage',
            'blurb'           => 'Securities brokerage. Update by uploading monthly statements or your year-end 1099.',
            'doc_types'       => [
                'statement' => 'Monthly Statement',
                'tax'       => 'Tax Document (1099)',
            ],
            'handler'         => 'webull',       // lib/manual/webull.php → webull_parse / webull_ingest
        ],
    ];
}

/** One type's config, or null if unknown. */
function manual_type(?string $key): ?array
{
    if ($key === null || $key === '') return null;
    return manual_types()[$key] ?? null;
}

/** Load the handler file for a type and return its config (or null). */
function manual_load_handler(?string $key): ?array
{
    $cfg = manual_type($key);
    if (!$cfg) return null;
    require_once __DIR__ . '/' . $cfg['handler'] . '.php';
    return $cfg;
}
