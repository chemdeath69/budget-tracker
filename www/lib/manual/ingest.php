<?php
declare(strict_types=1);

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/pdftext.php';
require_once __DIR__ . '/../sync.php';   // write_networth_snapshot()

/**
 * Generic ingest pipeline for manual-account documents. Type-agnostic: it
 * handles validation, text extraction, the dedup "bucket" logic, raw-file
 * retention, and net-worth refresh, then dispatches the type-specific parse +
 * write to the registered handler (e.g. webull_parse / webull_ingest).
 */

/** User-facing ingest failure (its message is safe to show on the page). */
class ManualIngestError extends RuntimeException {}

/** Base directory where original PDFs are kept (outside the web root). */
function manual_storage_dir(): string
{
    global $CONFIG;
    $dir = $CONFIG['storage']['manual_dir'] ?? '';
    if ($dir === '') {
        // Local-dev fallback (gitignored). On the server this is set in config.php
        // to an absolute path outside ~/www so it is never web-served.
        $dir = dirname(__DIR__, 3) . '/storage/manual';
    }
    return rtrim($dir, '/');
}

/** Keep a copy of the uploaded PDF; returns its absolute path. */
function manual_store_file(string $accountId, string $docType, string $period, string $tmpPath): string
{
    $base = manual_storage_dir();
    $acctDir = $base . '/' . preg_replace('/[^A-Za-z0-9_]/', '_', $accountId);
    if (!is_dir($acctDir) && !@mkdir($acctDir, 0700, true) && !is_dir($acctDir)) {
        throw new RuntimeException('Could not create storage dir: ' . $acctDir);
    }
    // Defense-in-depth: deny web access if this ever lands under a docroot.
    $deny = $base . '/.htaccess';
    if (!is_file($deny)) @file_put_contents($deny, "Require all denied\nDeny from all\n");

    $safePeriod = preg_replace('/[^0-9A-Za-z_-]/', '_', $period);
    $dest = $acctDir . '/' . $docType . '-' . $safePeriod . '.pdf'; // one slot per bucket
    if (!@copy($tmpPath, $dest)) {
        throw new RuntimeException('Could not store the PDF on the server.');
    }
    @chmod($dest, 0600);
    return $dest;
}

/**
 * Ingest one uploaded document for a manual account.
 *
 * @param array  $account  row with at least account_id + manual_type
 * @param string $tmpPath  path to the uploaded temp file
 * @param string $origName client filename (for display only)
 * @param int    $uid      uploader user id
 * @return array { status: created|replaced|duplicate, doc_type, period, summary[], message }
 * @throws ManualIngestError on anything the user should see.
 */
function manual_ingest(PDO $pdo, array $account, string $tmpPath, string $origName, int $uid): array
{
    $type = (string)($account['manual_type'] ?? '');
    $cfg  = manual_load_handler($type);
    if (!$cfg) throw new ManualIngestError('This account type does not support document uploads.');

    // Basic validation: must be a real PDF.
    $head = (string)@file_get_contents($tmpPath, false, null, 0, 5);
    if (strncmp($head, '%PDF-', 5) !== 0) {
        throw new ManualIngestError('That file is not a PDF. Please upload a PDF statement or 1099.');
    }

    $sha  = hash_file('sha256', $tmpPath) ?: '';
    $size = (int)@filesize($tmpPath);

    // Extract + type-specific parse (throws ManualIngestError if unrecognized).
    try {
        $text = pdf_extract_text($tmpPath);
    } catch (RuntimeException $e) {
        throw new ManualIngestError('Could not read text from that PDF (' . $e->getMessage() . ').');
    }
    $parseFn = $cfg['handler'] . '_parse';
    $parsed  = $parseFn($text);   // ['doc_type','period_key','summary',...]

    $docType = (string)$parsed['doc_type'];
    $period  = (string)$parsed['period_key'];
    $acctId  = (string)$account['account_id'];

    // Dedup bucket: (account, doc_type, period). Same file → no-op.
    $cur = $pdo->prepare('SELECT id, file_sha256, summary FROM manual_documents
                          WHERE account_id = ? AND doc_type = ? AND period_key = ?');
    $cur->execute([$acctId, $docType, $period]);
    $existing = $cur->fetch();
    if ($existing && hash_equals((string)$existing['file_sha256'], $sha)) {
        return [
            'status'   => 'duplicate',
            'doc_type' => $docType,
            'period'   => $period,
            'summary'  => $parsed['summary'] ?? [],
            'message'  => ucfirst($cfg['doc_types'][$docType] ?? $docType)
                          . ' for ' . $period . ' was already uploaded — no changes.',
        ];
    }
    $isCorrection = (bool)$existing;

    // Keep the original PDF (outside web root). Replaces the bucket's prior file.
    $stored = manual_store_file($acctId, $docType, $period, $tmpPath);

    $pdo->beginTransaction();
    try {
        // Upsert the document row (bucket UNIQUE → replace on correction).
        $pdo->prepare(
            'INSERT INTO manual_documents
                (account_id, manual_type, doc_type, period_key, file_sha256,
                 stored_path, original_name, byte_size, uploaded_by, uploaded_at)
             VALUES (:acct,:type,:doc,:period,:sha,:path,:orig,:size,:uid,NOW())
             ON DUPLICATE KEY UPDATE
                file_sha256=VALUES(file_sha256), stored_path=VALUES(stored_path),
                original_name=VALUES(original_name), byte_size=VALUES(byte_size),
                uploaded_by=VALUES(uploaded_by), uploaded_at=NOW()'
        )->execute([
            ':acct' => $acctId, ':type' => $type, ':doc' => $docType, ':period' => $period,
            ':sha' => $sha, ':path' => $stored, ':orig' => mb_substr($origName, 0, 255),
            ':size' => $size, ':uid' => $uid,
        ]);
        $docId = (int)($existing['id'] ?? $pdo->lastInsertId());

        // Type-specific write (holdings/trades/tax). Returns a UI summary.
        $ingestFn = $cfg['handler'] . '_ingest';
        $summary  = $ingestFn($pdo, $account, $parsed, $docId);

        // Persist the summary on the document row for later display.
        $pdo->prepare('UPDATE manual_documents SET summary = :s WHERE id = :id')
            ->execute([':s' => json_encode($summary), ':id' => $docId]);

        // Manual balances feed net worth; refresh today's snapshot now (the daily
        // cron then carries the value forward until the next statement).
        write_networth_snapshot($pdo);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $label = $cfg['doc_types'][$docType] ?? $docType;
    return [
        'status'   => $isCorrection ? 'replaced' : 'created',
        'doc_type' => $docType,
        'period'   => $period,
        'summary'  => $summary,
        'message'  => $label . ' for ' . $period . ($isCorrection ? ' updated (replaced prior upload).' : ' imported.'),
    ];
}
