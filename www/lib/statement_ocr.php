<?php
/**
 * statement_ocr.php — vision OCR for manual 401(k) statement photos (Session 55, TODO #25).
 *
 * Reads one or more statement page images (phone photos are fine — the vision model
 * tolerates skew/shadows) via the Anthropic (Claude) Messages API and returns a
 * provider-agnostic structured array the retirement-statement importer pre-fills a
 * review form from. NOTHING here writes to the DB or auto-saves — the page renders the
 * result for the owner to confirm first (see retirement_statement.php).
 *
 * Provider-agnostic by design: the two real statements differ a lot —
 *   • Empower (Visual Comfort): 4 funding sources, employee/employer split columns,
 *     discrete dividend/capital-gain/fee activity lines (dated, with units + price).
 *   • Sentry  (FortuNet):       1 source, no split, NO discrete activity (just a lump
 *     "gain or loss"), plus bonus annuity-income estimates.
 * So `activity[]` may be empty, `sources[]` may hold 1..N rows, and `holdings[]` is the
 * common spine. Tolerate missing fields everywhere (null, never invented).
 *
 * Cost: pay-as-you-go prepaid credits, ~1–2¢ per page on claude-sonnet-4-6
 * (config['anthropic']['model']); empty api_key disables the feature cleanly.
 *
 * Public API:
 *   statement_ocr_enabled(array $cfg): bool
 *   statement_ocr_extract(array $imagePaths, array $cfg): array
 *        → ['ok'=>bool, 'data'=>array|null, 'error'=>?string, 'warnings'=>string[],
 *           'usage'=>array|null]
 */

const STATEMENT_OCR_ENDPOINT   = 'https://api.anthropic.com/v1/messages';
const STATEMENT_OCR_VERSION    = '2023-06-01';
const STATEMENT_OCR_MAXTOKENS  = 4096;     // the schema is large (holdings+sources+activity); ~2¢ leaves headroom
const STATEMENT_OCR_TIMEOUT    = 90;       // seconds — vision calls are slow
const STATEMENT_OCR_MAX_IMAGES = 6;        // a statement is a handful of pages
const STATEMENT_OCR_MAX_BYTES  = 5 * 1024 * 1024;  // Anthropic per-image limit

/** Feature on only when a real key is present (empty string = disabled). */
function statement_ocr_enabled(array $cfg): bool
{
    return trim((string)($cfg['anthropic']['api_key'] ?? '')) !== '';
}

/**
 * An Anthropic-supported image media type for the file, or null.
 * Content-based first (uploaded temp files have no extension), extension as fallback.
 */
function statement_ocr_media_type(string $path): ?string
{
    $supported = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $info = @getimagesize($path);
    if ($info !== false && isset($info['mime']) && in_array($info['mime'], $supported, true)) {
        return $info['mime'];
    }
    return [
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',  'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
}

/**
 * The forced-output JSON schema. Using tool_choice on a single tool guarantees the
 * model replies with one tool_use block whose `input` matches this shape — no prose to
 * parse, no markdown fences. All fields are nullable; the model is told to use null
 * rather than guess.
 */
function statement_ocr_schema(): array
{
    $money = ['type' => ['number', 'null']];
    return [
        'type' => 'object',
        'properties' => [
            'provider'           => ['type' => ['string', 'null'], 'description' => 'Recordkeeper brand, e.g. "Empower", "Sentry".'],
            'plan_name'          => ['type' => ['string', 'null']],
            'participant_name'   => ['type' => ['string', 'null']],
            'account_identifier' => ['type' => ['string', 'null'], 'description' => 'Participant ID, contract number, or account number.'],
            'period_start'       => ['type' => ['string', 'null'], 'description' => 'Statement period start, YYYY-MM-DD.'],
            'period_end'         => ['type' => ['string', 'null'], 'description' => 'Statement period end / "as of" date, YYYY-MM-DD. This is the statement date.'],
            'balance'            => array_merge($money, ['description' => 'Ending total account balance.']),
            'vested_balance'     => $money,
            'holdings' => [
                'type' => 'array',
                'description' => 'One row per investment fund held. Target-date plans often have exactly one.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name'   => ['type' => 'string'],
                        'ticker' => ['type' => ['string', 'null'], 'description' => 'Ticker/CUSIP if shown, else null.'],
                        'units'  => $money,
                        'price'  => array_merge($money, ['description' => 'Per-unit/share price if shown.']),
                        'value'  => array_merge($money, ['description' => 'Ending market value of this fund.']),
                    ],
                    'required' => ['name'],
                ],
            ],
            'sources' => [
                'type' => 'array',
                'description' => 'Funding/source breakdown rows (e.g. Employee Before-Tax, Rollover, Employer Match). May be 1..N.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name'              => ['type' => 'string'],
                        'kind'              => ['type' => ['string', 'null'], 'enum' => ['employee', 'employer', 'rollover', 'other', null], 'description' => 'Best classification of the source.'],
                        'vested_percent'    => $money,
                        'beginning_balance' => $money,
                        'ending_balance'    => $money,
                        'contributions'     => array_merge($money, ['description' => 'Deposits into this source THIS period.']),
                        'gain_loss'         => $money,
                    ],
                    'required' => ['name'],
                ],
            ],
            'activity' => [
                'type' => 'array',
                'description' => 'Discrete activity lines (dividend, capital gain, fee, contribution) if itemized. Empty if the statement only shows a lump gain/loss.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'date'        => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD.'],
                        'type'        => ['type' => 'string', 'enum' => ['dividend', 'capital_gain', 'fee', 'contribution', 'interest', 'other']],
                        'description' => ['type' => ['string', 'null']],
                        'amount'      => array_merge($money, ['description' => 'Signed as printed: income/credit positive, fee/withdrawal negative.']),
                        'units'       => $money,
                        'price'       => $money,
                    ],
                    'required' => ['type'],
                ],
            ],
            'contributions_period' => [
                'type' => ['object', 'null'],
                'description' => 'Contribution totals for THIS statement period.',
                'properties' => ['employee' => $money, 'employer' => $money],
            ],
            'contributions_ytd' => [
                'type' => ['object', 'null'],
                'description' => 'Year-to-date contribution totals if shown.',
                'properties' => ['employee' => $money, 'employer' => $money],
            ],
            'notes' => ['type' => ['string', 'null'], 'description' => 'Any other notable figure (e.g. estimated lifetime/annuity income).'],
        ],
        'required' => ['balance', 'period_end', 'holdings'],
    ];
}

/** The extraction instruction sent alongside the images. */
function statement_ocr_prompt(): string
{
    return <<<TXT
You are reading photographed pages of a single retirement (401k/403b) account statement.
Extract the figures into the extract_statement tool. Rules:
- Use values EXACTLY as printed (no rounding); strip "$" and thousands commas.
- If a field is not shown, use null — never guess or compute a value the statement doesn't state.
- Dates as YYYY-MM-DD. The statement period end (the "as of" date) is period_end.
- holdings: one row per fund. Many 401(k)s hold a single target-date fund — that's normal.
- activity: include itemized dividend/capital-gain/fee/contribution lines only if the
  statement lists them discretely. If it only reports a single combined gain/loss, leave
  activity empty (do NOT fabricate dividend lines).
- amount sign: income/credits positive, fees/withdrawals negative, as printed.
- The pages belong to ONE account — merge them into one result, don't duplicate.
TXT;
}

/**
 * Run the extraction. Returns a normalized result envelope. Never throws.
 *
 * @param string[] $imagePaths absolute paths to image files (1..STATEMENT_OCR_MAX_IMAGES)
 */
function statement_ocr_extract(array $imagePaths, array $cfg): array
{
    $fail = fn(string $e) => ['ok' => false, 'data' => null, 'error' => $e, 'warnings' => [], 'usage' => null];

    $key = trim((string)($cfg['anthropic']['api_key'] ?? ''));
    if ($key === '')                     return $fail('Anthropic API key not configured.');
    if (!$imagePaths)                    return $fail('No images provided.');
    if (count($imagePaths) > STATEMENT_OCR_MAX_IMAGES) {
        return $fail('Too many pages (max ' . STATEMENT_OCR_MAX_IMAGES . ').');
    }
    $model = trim((string)($cfg['anthropic']['model'] ?? '')) ?: 'claude-sonnet-4-6';

    // Build the multimodal content: every image, then the instruction.
    $content = [];
    foreach ($imagePaths as $path) {
        if (!is_file($path) || !is_readable($path)) return $fail('Cannot read image: ' . basename($path));
        $bytes = filesize($path);
        if ($bytes === false || $bytes > STATEMENT_OCR_MAX_BYTES) {
            return $fail('Image too large (max 5 MB): ' . basename($path));
        }
        $media = statement_ocr_media_type($path);
        if ($media === null) return $fail('Unsupported image type: ' . basename($path));
        $raw = file_get_contents($path);
        if ($raw === false) return $fail('Cannot read image: ' . basename($path));
        $content[] = [
            'type'   => 'image',
            'source' => ['type' => 'base64', 'media_type' => $media, 'data' => base64_encode($raw)],
        ];
    }
    $content[] = ['type' => 'text', 'text' => statement_ocr_prompt()];

    $body = [
        'model'       => $model,
        'max_tokens'  => STATEMENT_OCR_MAXTOKENS,
        'tools'       => [[
            'name'         => 'extract_statement',
            'description'  => 'Return the structured figures extracted from the statement pages.',
            'input_schema' => statement_ocr_schema(),
        ]],
        'tool_choice' => ['type' => 'tool', 'name' => 'extract_statement'],
        'messages'    => [['role' => 'user', 'content' => $content]],
    ];

    $ch = curl_init(STATEMENT_OCR_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => STATEMENT_OCR_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: ' . STATEMENT_OCR_VERSION,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);
    $resp   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr   = curl_error($ch);
    curl_close($ch);

    if ($resp === false)  return $fail('Network error contacting Anthropic: ' . $cerr);
    $json = json_decode((string)$resp, true);
    if (!is_array($json)) return $fail('Unreadable response from Anthropic (HTTP ' . $status . ').');
    if ($status !== 200) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $status);
        return $fail('Anthropic error: ' . $msg);
    }

    // Pull the forced tool_use block's input.
    $data = null;
    foreach (($json['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'extract_statement') {
            $data = $block['input'] ?? null;
            break;
        }
    }
    if (!is_array($data)) return $fail('Model did not return structured data.');

    $data     = statement_ocr_normalize($data);
    $warnings = statement_ocr_validate($data);

    // A max_tokens stop means the forced tool_use JSON was truncated — it can still
    // decode to a partial array (e.g. activity[] cut short), so warn loudly rather than
    // silently pre-fill an incomplete read.
    if (($json['stop_reason'] ?? '') === 'max_tokens') {
        array_unshift($warnings, 'The statement was long and the read may be INCOMPLETE (the model hit its output limit) — double-check every figure, or retry with fewer/clearer pages.');
    }

    return [
        'ok'       => true,
        'data'     => $data,
        'error'    => null,
        'warnings' => $warnings,
        'usage'    => $json['usage'] ?? null,
    ];
}

/** Coerce numeric strings → floats and guarantee array keys exist (model may omit). */
function statement_ocr_normalize(array $d): array
{
    $num = function ($v) {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float)$v;
        $clean = str_replace([',', '$', ' '], '', (string)$v);
        return is_numeric($clean) ? (float)$clean : null;
    };

    foreach (['balance', 'vested_balance'] as $k) $d[$k] = $num($d[$k] ?? null);
    foreach (['holdings', 'sources', 'activity'] as $k) {
        if (!isset($d[$k]) || !is_array($d[$k])) $d[$k] = [];
    }
    foreach ($d['holdings'] as &$h) {
        foreach (['units', 'price', 'value'] as $k) $h[$k] = $num($h[$k] ?? null);
    }
    unset($h);
    foreach ($d['sources'] as &$s) {
        foreach (['vested_percent', 'beginning_balance', 'ending_balance', 'contributions', 'gain_loss'] as $k) {
            $s[$k] = $num($s[$k] ?? null);
        }
    }
    unset($s);
    foreach ($d['activity'] as &$a) {
        foreach (['amount', 'units', 'price'] as $k) $a[$k] = $num($a[$k] ?? null);
    }
    unset($a);
    foreach (['contributions_period', 'contributions_ytd'] as $k) {
        if (isset($d[$k]) && is_array($d[$k])) {
            $d[$k]['employee'] = $num($d[$k]['employee'] ?? null);
            $d[$k]['employer'] = $num($d[$k]['employer'] ?? null);
        } else {
            $d[$k] = null;
        }
    }
    return $d;
}

/** Soft sanity checks surfaced to the reviewer; never block, just flag. */
function statement_ocr_validate(array $d): array
{
    $w = [];
    if (($d['balance'] ?? null) === null)  $w[] = 'No ending balance was read — check the photo.';
    if (empty($d['period_end']))           $w[] = 'No statement date was read.';
    if (empty($d['holdings']))             $w[] = 'No fund/holding was read.';

    // Holdings should sum to the balance (within $1) when each carries a value.
    $vals = array_filter(array_column($d['holdings'], 'value'), fn($v) => $v !== null);
    if ($vals && ($d['balance'] ?? null) !== null) {
        $sum = array_sum($vals);
        if (abs($sum - (float)$d['balance']) > 1.0) {
            $w[] = sprintf('Holdings sum (%.2f) does not match the balance (%.2f).', $sum, (float)$d['balance']);
        }
    }

    // Per-holding sanity: a non-positive unit count or negative value/price means a
    // mis-read, and a printed price that disagrees with value÷units catches a garbled
    // figure even on a single-fund statement (where the sum-vs-balance check is blind).
    foreach ($d['holdings'] as $h) {
        $name = trim((string)($h['name'] ?? '')) ?: 'a fund';
        $u = $h['units'] ?? null; $v = $h['value'] ?? null; $p = $h['price'] ?? null;
        if ($u !== null && $u <= 0) $w[] = sprintf('"%s" has a non-positive unit count (%s) — check the photo.', $name, $u);
        if ($v !== null && $v < 0)  $w[] = sprintf('"%s" has a negative value — check the photo.', $name);
        if ($p !== null && $p < 0)  $w[] = sprintf('"%s" has a negative price — check the photo.', $name);
        if ($u !== null && $u > 1e-9 && $v !== null && $p !== null) {
            $derived = $v / $u;
            if (abs($p - $derived) > max(0.01, abs($derived) * 0.01)) {
                $w[] = sprintf('"%s": printed price (%.4f) disagrees with value÷units (%.4f).', $name, (float)$p, $derived);
            }
        }
    }
    return $w;
}
