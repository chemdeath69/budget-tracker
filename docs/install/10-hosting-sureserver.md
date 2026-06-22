# 10 · Hosting setup (sureserver) — subdomain, database, PHP

[← Back to the Installation Guide](../INSTRUCTIONS.md)

> **Preferred hosting: any cPanel-based provider.** This walkthrough was **tested on
> [ICDSoft](https://www.icdsoft.com)**, whose servers are **backed by sureserver / SureSupport** — so
> every `sureserver`/`panel.<server>.sureserver.com` reference here is that ICDSoft account. On a
> different cPanel host the panel layout differs, but the steps (subdomain → MySQL 8 DB + user → PHP
> 8.3) are the same.

This sets up the **place the app runs**: a subdomain, a MySQL 8 database + user, and the PHP 8.3
runtime. Two ways to do it:

- **[Guided installer](#guided-installer)** — run `tools/install.sh` and answer questions.
- **[Manual setup](#manual-setup)** — click through the control panel yourself.

Both produce the same result. Even if you use the installer, skim the manual steps so you know what
it created.

> **Placeholders used throughout.** Replace these with *your* values:
> | Placeholder | Meaning | Where to find it |
> |---|---|---|
> | `<server>` | your server id, e.g. `s446` | your welcome email / the panel URL |
> | `<cpuser>` | your control-panel username | welcome email / panel header |
> | `<domain>` | your registered domain, e.g. `yourdomain.com` | your registrar |
> | `<sub>` | the subdomain label you want, e.g. `budget` | you choose |
>
> Your app will live at **`https://<sub>.<domain>`** with web root **`~/www/<sub>`**
> (= `/home/<cpuser>/www/<sub>`).

---

## 0. Get a hosting account & find your details

1. Sign up for a **cPanel-based** shared-hosting plan that includes **MySQL 8**, **PHP 8.x**,
   **cron jobs**, and **SSH**. The reference deployment was tested on **[ICDSoft](https://www.icdsoft.com)**
   (sureserver / SureSupport backed; the "Economy" plan has all of these). Add or transfer your
   **domain** to the account.
2. From your **welcome email**, note your **server hostname** (`<server>.sureserver.com`) and your
   **control-panel username** (`<cpuser>`).
3. Log in to the control panel at:
   ```
   https://panel.<server>.sureserver.com/dashboard
   ```
   > Note: `https://<domain>/cpanel` does **not** work on this host — use the `panel.<server>…` URL.

The panel's left nav has everything you need. Handy deep links (append to
`https://panel.<server>.sureserver.com`):

| Section | Path |
|---|---|
| Subdomains | `/subdomains` |
| MySQL Databases | `/databases` |
| phpMyAdmin | `/databases/phpadmin` |
| PHP Settings | `/php` |
| Cron Jobs | `/crons` |
| SSH Access | `/ssh` |
| File Manager | `/files` |
| SSL / HTTPS | `/ssl` |
| DNS Manager | `/dns` |
| **Account Profile** (API key) | `/account` area → *Account Profile* |

---

<a name="guided-installer"></a>
## A. Guided installer (`tools/install.sh`)

The installer drives the **Control Panel API** so you don't have to click. It will:

1. create the subdomain + web root,
2. create the MySQL **8** database, a DB user, and grant privileges,
3. set the subdomain's PHP to **8.3 (FPM)**,
4. write your `config.php` to the server,
5. import the database schema,
6. create the nightly cron job.

It does **not** upload the application code (the API has no binary upload) and cannot click through
Plaid/Google — you do those by hand.

### A.1 Generate a Control Panel API key

1. In the panel, open **Account Profile** (`/account` area).
2. Find **API Key** / **API Access** → **Generate**.
3. Copy the key. It's sent in an `x-api-key` header and **does not expire** until you revoke it.
   Treat it like a password — it can create/delete everything on your account.

   *(Alternative: the API also accepts a 10-day Bearer token from `POST /auth` with your panel
   login. The installer uses the simpler API key.)*

### A.2 Run it

From a checkout of this repo on your laptop (macOS/Linux/WSL — needs `curl`):

```bash
cd budget-tracker
./tools/install.sh
```

It prompts for: your `<server>`, `x-api-key`, `<domain>`, `<sub>`, a database name/user/password,
and your service keys, then performs steps 1–6 above and prints exactly what it created. Full
details and a dry-run option are in **[`tools/install.sh`](../../tools/install.sh)** (run
`./tools/install.sh --help`).

After it finishes, continue at **[20 · Upload code](20-upload-code.md)** to push the `www/` files,
then **[60 · Verify](60-verify.md)**. (The installer already did config + schema + cron, but you
still upload the code and should verify.)

> **Order note:** because the code upload is manual, the installer uploads `config.php` and imports
> the schema **after** you've put the code in place, OR it writes `config.php` directly via the
> Files API (text) independently of the code. The script tells you which step to do when.

---

<a name="manual-setup"></a>
## B. Manual setup (control panel)

### B.1 Create the subdomain

1. Go to **Subdomains** (`/subdomains`).
2. **Create** a subdomain: label **`<sub>`**, domain **`<domain>`**. Accept the auto-suggested
   document root (it will be `www/<sub>`, i.e. `~/www/<sub>`).
3. Confirm it appears in the table. The wildcard **Let's Encrypt** cert (`*.<domain>`) usually
   covers HTTPS within a few minutes — verify under **SSL/HTTPS** (`/ssl`). If HTTPS isn't active
   shortly, issue/enable a Let's Encrypt cert for the subdomain there.

> **API equivalent** — `POST /api/v1/subdomains/create` with `subdomain=<sub>`, `domain=<domain>`.

### B.2 Create the MySQL **8** database + user

> ⚠️ **This host runs MySQL 5 *and* MySQL 8.** The app needs **MySQL 8**. Make sure you create the
> database on the **MySQL 8** server (the panel lets you choose; MySQL 8 is "8.x").

1. Go to **MySQL Databases** (`/databases`).
   - ⚠️ **This page opens on the MySQL 5 view by default** (the URL becomes `/databases/5`). Click the
     **MySQL 8** tab — or just open **`/databases/8`** directly — *before* you create anything, so the
     database lands on the right server. (The active tab also shows in the URL: `/databases/8` = MySQL 8.)
2. On the **MySQL 8** tab, click **Create new database**. The dialog does the database, the user, and
   the grant **in one step**:
   - **Database name:** something like `budget`. The panel will **prefix it** with your account, so
     the real name becomes **`<cpuser>_budget`**. *Note the exact final name it shows you.*
   - Choose **add new user** (the default), enter a **username** (e.g. `budget`) + a strong password
     (use the **Generate** button, then read the value before confirming), and **Create**.
   - ⚠️ **Username quirk:** on this host the **username is NOT prefixed** (it stays `budget`), even
     though the database name *is* prefixed (`<cpuser>_budget`). Using the prefixed name as the
     username gives `1045 Access denied`. **Use exactly what the panel displays for each.**
3. The "add new user" option **grants a full privilege set automatically** (SELECT, INSERT, UPDATE,
   DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES, CREATE TEMPORARY TABLES, …) — no
   separate add-user/grant step is needed in the UI. (If you instead created the user separately, add
   it to the database and grant **ALL PRIVILEGES**.) **Write the password down.**

> ⚠️ **MySQL 5 vs 8 trap (real, hit during the reference deployment):** the panel shows **both** a
> MySQL 5 and a MySQL 8 panel, and it's easy to create the DB on the wrong one — a "created
> successfully" toast appears either way. If you land on MySQL 5, **delete it and recreate on MySQL
> 8**, then verify with `GET /api/v1/databases/8` (the `8` selects the MySQL 8 server).

You'll put these into `config.php` later:

```php
'db' => [
    'socket'  => '/tmp/mysql8.sock',   // MySQL 8 socket on this host (preferred)
    'host'    => '127.0.0.1',          // fallback
    'port'    => 3308,                 // MySQL 8 TCP port on this host (NOT 3306)
    'name'    => '<cpuser>_budget',    // the PREFIXED database name
    'user'    => 'budget',             // the SHORT username (not prefixed)
    'pass'    => '…your db password…',
    'charset' => 'utf8mb4',
],
```

> ⚠️ **Do not use the default `localhost:3306`** — that's the **MySQL 5** server and the app will
> fail to find its tables. Use the socket `/tmp/mysql8.sock` (preferred) or `127.0.0.1:3308`.
> Confirm your host's MySQL 8 socket/port in the panel if these differ.

> **API equivalents** —
> `POST /api/v1/databases/8/create` (`name`, `collation=utf8mb4_general_ci`, `new_user`, `password`,
> `password_confirmation`), then
> `POST /api/v1/databases/8/users/create` and `POST /api/v1/databases/8/privileges`
> (`user`, `database`, `privileges[]`). The `8` in the path selects the **MySQL 8** server.

### B.3 Set the subdomain to PHP 8.3 (FPM)

1. Go to **PHP Settings** (`/php`). PHP version is set **per subdomain** here.
2. For **`<sub>.<domain>`**, choose **PHP 8.3** with the **FPM** handler (the reference deployment
   uses "FPM with max OPcache memory"; plain FPM is fine — only a couple of "max OPcache" slots
   exist, and **default-OPcache FPM works equally well**).
3. Save and confirm it persists on reload.

> Confirm the **`sodium`** extension is enabled for 8.3 (it is by default on this host). The app
> uses libsodium to encrypt Plaid tokens — without it, linking banks fails. Check under PHP
> extensions, or run `php -m | grep sodium` over SSH.

> **API equivalent** — `POST /api/v1/php/settings/save/<sub>.<domain>` with
> `settings[www][php_handler]=fpm`, `settings[www][php_version]=83`.

### B.4 (Optional) Add an SSH key — only if you'll upload via rsync

If you plan to deploy code with `rsync`/`deploy.sh` (the secondary upload method), add your public
key now:

1. Go to **SSH Access** (`/ssh`). Ensure SSH is **enabled**.
2. Under **SSH Keys**, **Import** your existing public key (`~/.ssh/id_ed25519.pub` or similar), or
   **Generate** a new keypair and download the private key.
3. Test: `ssh -i ~/.ssh/<yourkey> <cpuser>@<server>.sureserver.com` (port 22, key-only).

> If you'll use the **File Manager** upload method instead (recommended for most), you can skip SSH
> entirely. See [20 · Upload code](20-upload-code.md).

> **API equivalents** — `POST /api/v1/ssh/keys/import` (`key`) or `POST /api/v1/ssh/keys/generate`.

### B.5 SSL / HTTPS

Usually automatic: the account has a **wildcard `*.<domain>`** Let's Encrypt cert via AutoSSL, so
new subdomains get HTTPS within minutes. If `https://<sub>.<domain>` shows a cert warning, go to
**SSL/HTTPS** (`/ssl`) and issue/enable a Let's Encrypt certificate for the subdomain.

> Google sign-in and Plaid webhooks **require** valid HTTPS — don't proceed to verification until
> `https://<sub>.<domain>` loads with a trusted certificate.

---

## ✅ Checkpoint

You should now have:

- [ ] `https://<sub>.<domain>` resolving with valid HTTPS (it'll 404/403 until code is uploaded — that's fine),
- [ ] a **MySQL 8** database `<cpuser>_budget` + user with privileges (password saved),
- [ ] PHP **8.3 / FPM** set for the subdomain,
- [ ] (optional) an SSH key working, if you'll use rsync.

→ Next: **[20 · Upload the code](20-upload-code.md)**.
