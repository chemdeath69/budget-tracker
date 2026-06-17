# 30 · Config & secrets — `config.php`

[← Back to the Installation Guide](../INSTRUCTIONS.md)

The app reads **one** file for all of its settings and secrets:
**`www/lib/config.php`** (on the server: `~/www/<sub>/lib/config.php`). It does not exist yet — you
create it from the template **[`config.sample.php`](../../www/lib/config.sample.php)**.

> 🔒 **`config.php` is git-ignored and must never be committed or shared.** It holds your database
> password, Plaid secret, encryption key, and API keys. Keep a private backup somewhere safe (a
> password manager), especially the **`encryption_key`**.

---

## 1. Create the file

Pick whichever matches how you uploaded code:

- **File Manager:** copy `lib/config.sample.php` → `lib/config.php` (right-click → Copy), then edit
  `lib/config.php` in the File Editor.
- **SSH:** `cp ~/www/<sub>/lib/config.sample.php ~/www/<sub>/lib/config.php` then edit with `nano`.
- **Guided installer:** `tools/install.sh` writes `config.php` for you from your answers (via the
  Files API). You can still open it afterward to add optional keys.

---

## 2. Generate your two secret keys

These are **yours** — generate fresh random values, don't reuse anyone else's.

| Key | Purpose | Generate it |
|---|---|---|
| `encryption_key` | base64 of 32 random bytes — encrypts Plaid access tokens at rest | `openssl rand -base64 32` |
| `session_secret` | long random string — signs/secures sessions | `openssl rand -hex 32` |

```bash
# encryption_key  (e.g. "qBb1…=" — 44 chars ending in =)
openssl rand -base64 32

# session_secret  (64 hex chars)
openssl rand -hex 32
```

> If you have PHP locally instead of openssl:
> `php -r "echo base64_encode(random_bytes(32));"` and `php -r "echo bin2hex(random_bytes(32));"`.

> ⚠️ **Back up `encryption_key`.** If you lose or change it, every stored Plaid token becomes
> undecryptable and you must **re-link all banks**.

---

## 3. Fill in every section

Below is the full template with guidance per key. Required sections are marked ✅; optional ones can
be left blank (`''`) to disable that feature.

### `db` ✅ — from [step 10 · B.2](10-hosting-sureserver.md#manual-setup)

```php
'db' => [
    'socket'  => '/tmp/mysql8.sock',  // MySQL 8 socket (preferred on sureserver)
    'host'    => '127.0.0.1',         // fallback if socket unset
    'port'    => 3308,                // MySQL 8 TCP port (NOT 3306)
    'name'    => '<cpuser>_budget',   // the PREFIXED database name
    'user'    => 'budget',            // the SHORT username (not prefixed)
    'pass'    => 'YOUR_DB_PASSWORD',
    'charset' => 'utf8mb4',
],
```

### `google` ✅ — from [services/google-oauth.md](services/google-oauth.md)

```php
'google' => [
    'client_id'     => 'XXXX.apps.googleusercontent.com',
    'client_secret' => 'GOCSPX-XXXX',
    'redirect_uri'  => 'https://<sub>.<domain>/oauth-callback.php',  // MUST match the console exactly
],
```

### `plaid` ✅ — from [services/plaid.md](services/plaid.md)

```php
'plaid' => [
    'env'            => 'production',   // 'production' | 'sandbox'
    'client_id'      => 'YOUR_PLAID_CLIENT_ID',
    'secret'         => 'YOUR_PLAID_PRODUCTION_SECRET',
    'webhook_url'    => 'https://<sub>.<domain>/webhook.php',
    'days_requested' => 730,           // history (days) to pull on first link
],
```

### `allowed_emails` ✅ — who may sign in

```php
// ONLY these Google accounts may sign in. Everyone else is rejected.
'allowed_emails' => [
    'you@gmail.com',
    'partner@gmail.com',
],
```

> Use the **exact** Google account emails (the address Google reports in the sign-in token). Add the
> second user here whenever you're ready.

### `encryption_key` / `session_secret` ✅ — from step 2 above

```php
'encryption_key' => 'PASTE_openssl_rand_base64_32_HERE',
'session_secret' => 'PASTE_openssl_rand_hex_32_HERE',
```

### `alerts` — email alerts (transport + fallback)

```php
'alerts' => [
    'recipients'         => ['you@gmail.com'],
    'large_tx_threshold' => 200.0,             // USD fallback (live value editable in Settings)
    'from'               => 'budget@<domain>', // a From address on your domain
],
```

> The live on/off toggles + threshold are stored in the database and edited on the **Settings →
> Alerts** page; these config values are just transport defaults. Use a `from` address that exists
> on your domain (or a mailbox you create in the panel) so mail isn't rejected.

### `storage.manual_dir` + `pdftotext` — manual document uploads (optional feature)

```php
'storage' => [
    'manual_dir' => '/home/<cpuser>/www/<sub>/storage/manual',
],
'pdftotext' => '/usr/bin/pdftotext',   // poppler; only used for Webull-style PDF uploads
```

> The app creates `storage/manual` and writes a web-deny `.htaccess` there automatically on first
> use. If your host has `pdftotext` elsewhere, set the right path (`which pdftotext` over SSH). If
> you'll never upload brokerage PDFs, these can stay as-is — they're harmless.

### Optional data feeds — leave `''` to disable each

```php
'twelvedata' => ['api_key' => ''],   // services/twelvedata.md  (security prices)
'fred'       => ['api_key' => ''],   // services/fred.md        (economic data)
'polygon'    => ['api_key' => ''],   // services/polygon.md     (dividends)
'rentcast'   => ['api_key' => ''],   // services/rentcast.md    (home value — PAID risk)
'anthropic'  => [                    // services/anthropic.md   (OCR + AI assistant — PAID)
    'api_key'         => '',
    'model'           => 'claude-sonnet-4-6',
    'assistant_model' => '',         // empty = use `model`
],
'home' => ['address' => ''],         // 'Street, City, State, Zip' for RentCast; blank = disabled
```

> ⚠️ **Empty string = off; a non-empty placeholder = "on".** For `fred`, `polygon`, etc., a leftover
> placeholder like `ENTERKEYHERE` is treated as a real key — the nightly job will call the provider
> and log auth errors. Leave it exactly `''` until you paste a real key.

---

## 4. Save & (if using rsync) upload it

- **File Manager / installer:** it's already on the server.
- **rsync:** `./deploy.sh config` (or scp `lib/config.php` to `~/www/<sub>/lib/config.php`).

Double-check `config.php` is present at `~/www/<sub>/lib/config.php` and that
`lib/config.sample.php` is still there too (the app doesn't need the sample, but it's harmless).

---

## ✅ Checkpoint

- [ ] `lib/config.php` exists on the server with **real** `db`, `google`, `plaid`, `allowed_emails`,
      `encryption_key`, `session_secret` values,
- [ ] optional keys are either real or **empty strings** (no placeholders),
- [ ] you saved a private backup of `encryption_key`.

→ Next: **[40 · Load the database schema](40-database-schema.md)**.
