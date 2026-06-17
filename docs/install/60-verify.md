# 60 · Verify everything works

[← Back to the Installation Guide](../INSTRUCTIONS.md)

Final step: sign in, link a real bank, pull data, and smoke-test the app.

---

## 1. Load the site & sign in

1. Open **`https://<sub>.<domain>`** in a browser.
2. You should see the **login page** with a **Sign in with Google** button (page = `login.php`).
   - *Blank page / HTTP 500?* `display_errors` is off in production, so a fatal shows as an empty
     500. Almost always a `config.php` problem (DB creds / MySQL 8 socket) or schema not loaded. See
     [troubleshooting](troubleshooting.md).
3. Click **Sign in with Google** and choose an account that is in your `allowed_emails`.
   - It redirects to Google, then back to **`/oauth-callback.php`**, then into the dashboard.
   - *"Redirect URI mismatch"* from Google → the redirect URI in the Cloud Console doesn't exactly
     match `https://<sub>.<domain>/oauth-callback.php`. Fix it in
     [services/google-oauth.md](services/google-oauth.md).
   - *Signed out / "not allowed"* → the Google account's email isn't in `allowed_emails`, or it's
     spelled differently. Fix `config.php` and re-upload.

You should land on the **dashboard** (empty — no accounts yet).

---

## 2. Link your first bank (Plaid)

1. In the app, go to **Settings → Banks & accounts** (or the **Link a bank** action) — this opens
   **Plaid Link**.
2. Choose your bank, authenticate, pick accounts, finish. Plaid Link returns to the app and the new
   accounts appear.
   - *Plaid Link won't open / errors immediately* → check `plaid.env` is `production`, your
     `client_id`/`secret` are the **Production** keys, and (for OAuth banks) your redirect URI is
     registered in the Plaid dashboard. See [services/plaid.md](services/plaid.md).
   - *Bank links but no transactions yet* → normal; transactions arrive on the first sync/webhook.

---

## 3. Pull data immediately

Don't wait for the nightly cron:

- Click the in-app **Refresh** (dashboard or Settings), **or**
- run the sync manually over SSH / **Run now** in the panel:
  ```bash
  /usr/local/bin/php83.cli /home/<cpuser>/www/<sub>/cron/sync.php
  ```

Give it a moment, reload the dashboard — balances, transactions, and net worth should populate.

---

## 4. Smoke-test the pages

Click through the main nav and confirm each renders without error:

| Page | What you should see |
|---|---|
| **Dashboard** (`index.php`) | Net-worth figure, accounts grouped by type, a "needs attention" feed |
| **Transactions** | Your synced transactions, filterable |
| **Spending / Trends** | Category breakdown (after a little data) |
| **Net worth / Cash flow** | Charts render (no blank canvases) |
| **Investments / Retirement** | Holdings, if you linked a brokerage |
| **Settings → Activity / Sync status** | The sync run you just triggered, with per-step results |

Optional-feature pages only light up if you added the matching key:

| Page | Needs |
|---|---|
| **Economic** | `fred.api_key` |
| **Investments → Dividend calendar** | `polygon.api_key` |
| **Investments price charts / change icons** | `twelvedata.api_key` |
| **Home value card** | `rentcast.api_key` + `home.address` |
| **AI assistant**, **401(k) photo import** | `anthropic.api_key` |

If a feature's page shows an empty-state, that's the expected "key not set" behavior — add the key
in `config.php`, re-upload, and re-run the cron.

---

## 5. Add the second user

1. Add their Google email to `allowed_emails` in `config.php`, re-upload it.
2. Have them open `https://<sub>.<domain>` and sign in with Google.
3. They can link their **own** banks; account **visibility** (shared / private / hidden) is set per
   account in Settings, so each person controls what the other sees.

---

## 🎉 Done

You have a working budget-tracker. From here:

- **Back up `encryption_key`** (losing it = re-link every bank).
- Add any optional API keys you skipped, whenever you like.
- Keep the domain renewed.
- For code updates later: re-upload `www/` (re-zip, or `./deploy.sh`), and run any **new** migration
  named in [`HANDOFF.md`](../HANDOFF.md) — see [40 · Upgrading](40-database-schema.md#upgrading).

Problems? → **[troubleshooting.md](troubleshooting.md)**.
