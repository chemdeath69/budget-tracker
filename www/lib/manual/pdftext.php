<?php
declare(strict_types=1);

/**
 * Extract text from a PDF using poppler's `pdftotext` (installed on the host at
 * /usr/bin/pdftotext). `-layout` preserves the column alignment the Webull
 * parsers rely on. Pure shell-out — no vendored PHP PDF library, no build step.
 */

/** Resolve the pdftotext binary (config override → common paths → PATH). */
function pdf_bin(): string
{
    global $CONFIG;
    $candidates = [];
    if (!empty($CONFIG['pdftotext'])) $candidates[] = $CONFIG['pdftotext'];
    $candidates[] = '/usr/bin/pdftotext';
    $candidates[] = '/usr/local/bin/pdftotext';
    foreach ($candidates as $b) {
        if (is_string($b) && $b !== '' && @is_executable($b)) return $b;
    }
    return 'pdftotext'; // last resort: rely on PATH
}

/**
 * Return the document's text. Tries `-layout` first (column-aligned, best for
 * tables); falls back to the default flow if that yields nothing.
 *
 * @throws RuntimeException if extraction fails entirely.
 */
function pdf_extract_text(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('PDF not found or unreadable: ' . $path);
    }
    $bin = pdf_bin();
    $layout = shell_exec(escapeshellcmd($bin) . ' -layout -nopgbrk -enc UTF-8 '
        . escapeshellarg($path) . ' - 2>/dev/null');
    if (is_string($layout) && trim($layout) !== '') {
        return $layout;
    }
    // Fallback: default (reading-order) extraction.
    $plain = shell_exec(escapeshellcmd($bin) . ' -nopgbrk -enc UTF-8 '
        . escapeshellarg($path) . ' - 2>/dev/null');
    if (is_string($plain) && trim($plain) !== '') {
        return $plain;
    }
    throw new RuntimeException('pdftotext produced no text (scanned/image PDF, or binary missing).');
}
