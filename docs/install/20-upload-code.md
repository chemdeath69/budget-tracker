# 20 · Upload the application code

[← Back to the Installation Guide](../INSTRUCTIONS.md)

You need to get the contents of the repo's **`www/`** folder into your web root
**`~/www/<sub>`** (= `/home/<cpuser>/www/<sub>`).

> **Upload `www/`'s *contents*, not the `www/` folder itself.** After upload, the server should have
> `~/www/<sub>/index.php`, `~/www/<sub>/lib/`, `~/www/<sub>/cron/`, `~/www/<sub>/assets/`, etc. —
> **not** `~/www/<sub>/www/index.php`.

Two methods:

- **[Method 1 — File Manager (zip upload)](#method-1)** — recommended, no terminal needed.
- **[Method 2 — SSH + rsync](#method-2)** — fastest for repeat deploys, needs SSH set up.

---

<a name="method-1"></a>
## Method 1 — File Manager (zip upload) · recommended

### 1. Make a zip of `www/`

On your computer, from a checkout of this repo:

```bash
cd budget-tracker
# Zip the CONTENTS of www/ (note the trailing /. ), excluding any local secrets:
cd www
zip -r ../budget-www.zip . -x 'lib/config.php' -x '.DS_Store' -x '.playwright-mcp/*'
cd ..
```

You now have `budget-www.zip` containing `index.php`, `lib/`, `cron/`, `assets/`, … at its top level.

> Don't have the repo? Download it from your source (GitHub release / the owner's bundle) and zip
> the `www/` contents the same way. **Never include `lib/config.php`** — you'll create that fresh in
> [step 30](30-config-and-secrets.md).

### 2. Upload + extract

1. Panel → **File Manager** (`/files`).
2. Navigate into **`www/<sub>`** (your subdomain's document root).
3. **Upload** `budget-www.zip` into that folder.
4. **Extract** it *in place* (right-click → Extract, or the Extract toolbar action). Confirm you now
   see `index.php`, `lib/`, `cron/`, `assets/` directly inside `www/<sub>`.
5. Delete the uploaded `budget-www.zip` to keep things tidy.

After extracting, the web root should list the app's folders (`api/`, `assets/`, `cron/`, `lib/`,
`storage/`, …) and `index.php` directly inside `www/<sub>`:

> **API equivalent** (used by the guided installer's optional helper): after you upload the zip,
> `POST /api/v1/files/unarchive` with `archive=/www/<sub>/budget-www.zip`, `subdir=0`, `overwrite=1`
> extracts it. (There is **no** binary-upload API endpoint, which is why the zip upload itself is a
> manual File-Manager step.)

### 3. Permissions

The extracted files should already be readable by the web server. If pages later return *403
Forbidden*, set the web root to typical shared-hosting perms (directories `755`, files `644`) via
File Manager (select all → Permissions), or over SSH:

```bash
find ~/www/<sub> -type d -exec chmod 755 {} \;
find ~/www/<sub> -type f -exec chmod 644 {} \;
```

---

<a name="method-2"></a>
## Method 2 — SSH + rsync (`deploy.sh`)

For this you need the SSH key set up in [10 · B.4](10-hosting-sureserver.md#manual-setup). This is
how the reference deployment ships code and is the fastest way to push updates.

### Using the included `deploy.sh`

The repo's [`deploy.sh`](../../deploy.sh) rsyncs `www/` to the server, **excluding `lib/config.php`**
so your live secrets are never overwritten. Edit the top of the script for your account:

```bash
HOST="<cpuser>@<server>.sureserver.com"
KEY="$HOME/.ssh/<yourkey>"
DEST="~/www/<sub>/"
```

Then:

```bash
./deploy.sh          # rsync the code (excludes lib/config.php)
./deploy.sh config   # upload lib/config.php separately (after you create it in step 30)
```

> `deploy.sh` uses `rsync` **without `--delete`**, so panel-managed files (php.ini, etc.) survive.

### Or rsync by hand

```bash
rsync -avz --no-perms --omit-dir-times \
  -e "ssh -i ~/.ssh/<yourkey>" \
  --exclude 'lib/config.php' --exclude '.git' --exclude '.DS_Store' \
  www/  <cpuser>@<server>.sureserver.com:~/www/<sub>/
```

---

## ✅ Checkpoint

- [ ] `~/www/<sub>/index.php` exists on the server (and `lib/`, `cron/`, `assets/` alongside it),
- [ ] `lib/config.php` does **not** exist yet (you'll create it next),
- [ ] visiting `https://<sub>.<domain>` now returns a PHP error / redirect rather than a 404
      (a 500 here is expected until `config.php` exists — see
      [troubleshooting](troubleshooting.md)).

→ Next: **[30 · Config & secrets](30-config-and-secrets.md)**.
