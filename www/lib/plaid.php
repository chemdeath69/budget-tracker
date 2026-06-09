<?php
declare(strict_types=1);

/**
 * Minimal Plaid REST client (cURL). All endpoints are POST + JSON.
 * Base URL + credentials come from config.php ['plaid'].
 *
 * Usage:
 *   $res = plaid_call('/accounts/balance/get', ['access_token' => $tok]);
 * client_id + secret are injected automatically.
 */

function plaid_base_url(): string
{
    global $CONFIG;
    $env = $CONFIG['plaid']['env'] ?? 'production';
    return $env === 'sandbox'
        ? 'https://sandbox.plaid.com'
        : 'https://production.plaid.com';
}

/**
 * Make a Plaid API call. Returns decoded array on success.
 * Throws PlaidException on transport or API error.
 */
function plaid_call(string $path, array $body = []): array
{
    global $CONFIG;
    $p = $CONFIG['plaid'];

    $body['client_id'] = $p['client_id'];
    $body['secret']    = $p['secret'];

    $url = plaid_base_url() . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $resp   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $err    = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $errno !== 0) {
        throw new PlaidException("Plaid transport error ($path): $err", 0, null, null);
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new PlaidException("Plaid returned non-JSON ($path), HTTP $status");
    }

    if ($status >= 400 || isset($data['error_code'])) {
        $code = $data['error_code'] ?? "HTTP_$status";
        $msg  = $data['error_message'] ?? 'Unknown Plaid error';
        throw new PlaidException("Plaid API error ($path): $code — $msg", $status, $data['error_code'] ?? null, $data['error_type'] ?? null);
    }

    return $data;
}

class PlaidException extends RuntimeException
{
    public ?string $plaidCode;
    public ?string $plaidType;
    public function __construct(string $message, int $http = 0, ?string $code = null, ?string $type = null)
    {
        parent::__construct($message, $http);
        $this->plaidCode = $code;
        $this->plaidType = $type;
    }
}

/* ---- Convenience wrappers for the endpoints this app uses ---- */

/**
 * Create a link_token.
 * - New link (no access token): required product = transactions; liabilities &
 *   investments requested as optional consent (tolerated even where unsupported).
 * - Update mode (access token given): products omitted. additional_consented_products
 *   MUST be filtered to what the institution supports — Plaid hard-rejects unsupported
 *   ones in update mode — so the caller passes the already-filtered $additionalConsent.
 */
function plaid_create_link_token(string $clientUserId, ?string $accessToken = null, ?array $additionalConsent = null): array
{
    global $CONFIG;
    $body = [
        'user'          => ['client_user_id' => $clientUserId],
        'client_name'   => 'Budget Tracker',
        'language'      => 'en',
        'country_codes' => ['US'],
        'webhook'       => $CONFIG['plaid']['webhook_url'],
    ];
    if ($accessToken) {
        $body['access_token'] = $accessToken;
        $consent = $additionalConsent ?? [];           // update mode: caller-filtered
    } else {
        $body['products'] = ['transactions'];
        $consent = $additionalConsent ?? ['liabilities', 'investments'];
    }
    if (!empty($consent)) {
        $body['additional_consented_products'] = array_values($consent);
    }
    return plaid_call('/link/token/create', $body);
}

/** Products supported by an institution (e.g. to filter additional consent in update mode). */
function plaid_institution_products(string $institutionId): array
{
    try {
        $res = plaid_call('/institutions/get_by_id', [
            'institution_id' => $institutionId,
            'country_codes'  => ['US'],
        ]);
        return $res['institution']['products'] ?? [];
    } catch (Throwable $e) {
        return [];
    }
}

function plaid_exchange_public_token(string $publicToken): array
{
    return plaid_call('/item/public_token/exchange', ['public_token' => $publicToken]);
}

/**
 * Force an immediate on-demand transactions check with the institution.
 * Asynchronous: Plaid checks the bank and later fires SYNC_UPDATES_AVAILABLE when
 * new data is ready (→ our webhook → sync). Returns {request_id} only — no
 * transactions in the response. Pair with a /transactions/sync to pull what's there.
 */
function plaid_refresh_transactions(string $accessToken): array
{
    return plaid_call('/transactions/refresh', ['access_token' => $accessToken]);
}
