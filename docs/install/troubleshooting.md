# Troubleshooting & gotchas

[← Back to the Installation Guide](../INSTRUCTIONS.md)

The hard-won issues an installer is most likely to hit, with the fix for each. Most are specific to
the **sureserver / SureSupport** host; the general ones apply anywhere.

---

## Blank page / HTTP 500, no error shown

`display_errors` is **off** in production, so any PHP fatal renders as an **empty 500** (blank body).
Don't guess — reproduce the error:

- **Read the cron log** for a CLI trace: `tail -n 80 ~/www/<sub>/storage/cron.log`.
- **Reproduce over SSH** with the 8.3 CLI to see the actual error:
  ```bash
  /usr/local/bin/php83.cli -r 'require "/home/<cpuser>/www/<sub>/lib/bootstrap.php";'
  ```
- 90% of fresh-install 500s are one of: **wrong DB settings**, **schema not loaded**, or a
  **syntax error in `config.php`** (a stray comma/quote). Re-check those first.

---

## Database

**`SQLSTATE … 1045 Access denied`**
- You used the **prefixed name as the username**. On this host the **database** is prefixed
  (`<cpuser>_budget`) but the **user is not** (`budget`). Use exactly what the panel shows for each.

**Connects but "table doesn't exist" / everything empty**
- You're talking to the **wrong MySQL server**. The default `localhost:3306` is **MySQL 5**. Use the
  **MySQL 8** socket `/tmp/mysql8.sock` (preferred) or `127.0.0.1:3308` in `config.php` `db`.

**`mysql < schema.sql` fails to authenticate**
- The CLI `mysql` client here is **5.6** and can't auth to MySQL 8. Load the schema via **phpMyAdmin**,
  the **Control Panel API import**, or the **PDO runner** (`./deploy.sh schema`). See
  [40 · Schema](40-database-schema.md).

**Sudden 500 on a search/filter page (`HY093`)**
- A native-prepared statement reused a named placeholder (`:x` twice). The app's DB layer runs with
  emulation **off**, which rejects that. This is a code-level bug, not an install issue — but if you
  hit it after editing queries, bind distinct names.

---

## PHP / cron

**Cron "runs" but nothing happens / `sodium` errors**
- The default `php` is **5.6** and lacks `sodium`. Cron must call **`/usr/local/bin/php83.cli`**
  explicitly. Re-check your cron command.

**Cron never runs at all**
- A **failed log redirect** kills the whole line. The home dir is root-owned, so `>> ~/cron.log`
  fails with *Permission denied*. Log **under the web root**:
  `>> /home/<cpuser>/www/<sub>/storage/cron.log 2>&1`, and `mkdir -p` that `storage/` folder first.

**`Failed loading … ioncube.so … undefined symbol` in the log**
- Harmless. The ionCube loader is mismatched for the CLI build; it does **not** affect execution.
  Ignore it, or it'll just be noise at the top of each run.

**`sodium` not found when linking a bank**
- Enable the `sodium` extension for PHP 8.3 on the subdomain (PHP Settings → Extensions), or confirm
  with `/usr/local/bin/php83.cli -m | grep sodium`.

---

## Timezone trap

The **server clock is US/Eastern** but the app's logical timezone is **US/Pacific**, and the DB
session has no `time_zone` set. **Never compare a MySQL `NOW()`/`CURDATE()` value to PHP `time()`** —
they're ~3 hours apart and will produce off-by-a-day bugs. (Relevant only if you modify code; the
shipped code already handles this. A "stale" file mtime just before ~01:13 ET means tonight's cron
hasn't fired yet, not that it failed.)

---

## Sign-in (Google)

| Symptom | Fix |
|---|---|
| `redirect_uri_mismatch` | The Cloud Console redirect URI must equal `config.php` `redirect_uri` **exactly** (`https`, host, `/oauth-callback.php`, no trailing slash). |
| Signs in then bounced out ("not authorised") | On a fresh install the first login is auto-admitted (and becomes admin) — if rejected, the DB isn't empty. Otherwise an admin must **invite** the email in **Settings → Users & access**, or it differs (`googlemail.com` vs `gmail.com`). Your break-glass `allowed_emails` always works. See [70 · Users & admin](70-users-and-admin.md). |
| "App not verified" warning | Expected in **Testing** mode → **Advanced → Continue** (it's your own app). The app uses only `openid email profile`, so no verification is required. |
| **Lost the client secret** | The new console shows it **only once at creation** (Download JSON / copy). Afterward it's `••••last4`. Open the client → **Additional information → Add client secret**, copy the new one, update `config.php`. |
| Console looks different than the docs | Newer console nests consent + clients under **APIs & Services → Google Auth Platform** (`/auth/overview`). Same fields, new wrapper. |

See [services/google-oauth.md](services/google-oauth.md).

---

## Plaid

| Symptom | Fix |
|---|---|
| **No Production secret** ("You don't have access") | New accounts are **Sandbox-only**. Click **Get full access**, complete the questionnaire (address/DOB/last-4 SSN/citizenship + a short product description), and wait for Plaid's review. Until then, deploy on **Sandbox** (`env: 'sandbox'` + the Sandbox secret, test bank `user_good`/`pass_good`) and flip to Production once granted. |
| "Verify your identity" popup when saving a redirect URI/webhook | Plaid gates dashboard changes behind a re-auth — click **Verify with Google** with your signup account, then the change saves. |
| Link won't open | `client_id`/`secret` must match `env` (the **Production** pair for `env: 'production'`). |
| Bank fails with a redirect/OAuth error | Register `https://<sub>.<domain>/link.php` under **Developers → API → Allowed redirect URIs** in the Plaid dashboard. |
| Linked but empty | Data arrives on the first webhook/sync — click **Refresh** or run the cron once. The webhook is sent per `link_token` from `config.php` `webhook_url`; no dashboard webhook config is needed. |

See [services/plaid.md](services/plaid.md).

---

## Optional feeds doing nothing — or logging errors

- **Nothing happens:** the key is probably blank (= disabled, expected) or the cron hasn't run yet.
  Run it once.
- **Auth errors in the log for FRED / Polygon / Anthropic:** you left a **non-empty placeholder**
  (e.g. `ENTERKEYHERE`) instead of `''`. Empty string disables; a placeholder is treated as a real
  key. Set it to `''` or a real key.
- **RentCast card missing:** you need **both** `rentcast.api_key` **and** `home.address` set. Watch
  the 50/month cap (the app enforces it; see [services/rentcast.md](services/rentcast.md)).

---

## Permissions / files

- **403 Forbidden on pages:** set dirs `755`, files `644` in the web root (File Manager → Permissions,
  or `find … -exec chmod`).
- **Uploaded the folder wrong:** you want `~/www/<sub>/index.php`, **not** `~/www/<sub>/www/index.php`.
  Re-extract the zip's *contents* into the docroot.

---

## Secrets hygiene (don't skip)

- **Never commit `config.php`** or paste real keys into any tracked file. `.gitignore` already covers
  `config.php`, `docs/CREDENTIALS.local.md`, and `*.png`.
- If a secret leaks into git history, **rotate it** in the provider's dashboard (it's compromised) and
  scrub it.
- **Back up `encryption_key`** outside the server. Losing it = re-link every bank.

---

## Control Panel API — validated quirks

If you script against the API yourself (or debug `install.sh`), these were confirmed live against
the panel and are baked into the installer:

- **Auth/encoding:** `x-api-key` header; **JSON request bodies work** for everything (the one
  exception below). Base URL `https://panel.<server>.sureserver.com/api/v1`.
- **Database paths are version-scoped:** `…/databases/8/...` targets **MySQL 8** (the docs' `5`
  examples target MySQL 5). Pick `8`.
- **`databases/8/create` can return a 422 (`common.unexpected_error`) while still creating the DB.**
  Don't trust the status — **verify with `GET /databases/8`**. Create the user separately
  (`databases/8/users/create`) then grant (`databases/8/privileges`).
- **`databases/8/import` `collation` must be a CHARSET** (`utf8mb4`), not a collation
  (`utf8mb4_general_ci` → the import task fails). Import is **async** — poll `GET /tasks/details?id=`.
- **Files: `write-file` only overwrites an existing file.** Create it first with `create-file`
  (`dir` + `name`), then `write-file` (`file` + `content`). There is **no binary-upload** endpoint
  (hence the manual zip step for code). Paths look like `/www/<sub>/lib/config.php`.
- **Cron minutes are slotted** — `minute: "0"` is rejected (`crons.invalid_time`). Use `"*/60"`; the
  host assigns the actual minute (e.g. `:13`). Delete a cron by POSTing its exact `cron` line.
- **`php/settings/save` is finicky** (returned `common.unexpected_error` in testing). New subdomains
  already default to **PHP 8.3 / FPM**, so the installer treats this step as best-effort — set/verify
  the PHP version in the panel if needed.

## Still stuck?

- Re-check your control panel's own API/docs for the exact endpoints + payloads your host exposes
  (subdomain, MySQL, PHP version, cron) — the names differ slightly per provider.
- The application code under `www/` is the source of truth for behavior; the database schema is in
  [`../schema.sql`](../schema.sql).
