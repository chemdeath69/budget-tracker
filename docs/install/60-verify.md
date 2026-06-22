# 60 · Verify everything works

[← Back to the Installation Guide](../INSTRUCTIONS.md)

Final step: sign in, link a real bank, pull data, and smoke-test the app.

---

## 1. Load the site & sign in

1. Open **`https://<sub>.<domain>`** in a browser.
2. You should see the **login page** with a **Continue with Google** button (page = `login.php`).
   - *Blank page / HTTP 500?* `display_errors` is off in production, so a fatal shows as an empty
     500. Almost always a `config.php` problem (DB creds / MySQL 8 socket) or schema not loaded. See
     [troubleshooting](troubleshooting.md).

3. Click **Continue with Google**. On a **fresh install** the login page says *"become the
   administrator"* — the **first** account to sign in is auto-allowed and made admin (you invite
   everyone else afterward, see [§5](#5-add-the-second-user)). Choose the account that should own
   the app.

   - In **Testing** mode Google may first warn *"Google hasn't verified this app"* → **Advanced →
     Continue** (it's your own app). Then grant the **email + profile** consent:

   - It redirects to Google, then back to **`/oauth-callback.php`**, then into the dashboard.
   - *"Redirect URI mismatch"* from Google → the redirect URI in the Cloud Console doesn't exactly
     match `https://<sub>.<domain>/oauth-callback.php`. Fix it in
     [services/google-oauth.md](services/google-oauth.md).
   - *Signed out / "not authorised"* → on a **fresh** install this shouldn't happen (the first
     login is auto-admitted); if it does, the database isn't actually empty. On an **existing**
     install it means an admin hasn't invited that email yet (Settings → Users & access), or it's
     spelled differently (e.g. `gmail.com` vs `googlemail.com`).

You should land on the **dashboard** — empty, with a **"No accounts linked yet → Link a bank
account"** card. That confirms the whole chain works: Google OAuth (redirect URI + test users +
the first-login bootstrap), the DB connection, and the schema.

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

The first account that signed in (you) is automatically the **administrator**. Add others **from
inside the app** — no file editing:

1. Go to **Settings → Users & access → Invite**, enter their **Google email**, pick Member or Admin.
2. If your Google consent screen is in **Testing** mode, also add their email as a Google **Test
   user** (Cloud Console → OAuth consent screen). Otherwise they're blocked by Google before they
   reach the app. See [70 · Users & admin §4](70-users-and-admin.md#4-the-google-sign-in-caveat-important).
3. Have them open `https://<sub>.<domain>` and sign in with Google.
4. They can link their **own** banks; account **visibility** (shared / private / hidden) is set per
   account in Settings, so each person controls what the other sees.

> Full details on roles, removing access (keeps their data), and **Factory Reset** are in
> [`70-users-and-admin.md`](70-users-and-admin.md).

---

## 🎉 Done

You have a working budget-tracker. From here:

- **Back up `encryption_key`** (losing it = re-link every bank).
- Add any optional API keys you skipped, whenever you like.
- Keep the domain renewed.
- For code updates later: re-upload `www/` (re-zip, or `./deploy.sh`), and run any **new** migration
  in `www/lib/migrations/` — see [40 · Upgrading](40-database-schema.md#upgrading).

Problems? → **[troubleshooting.md](troubleshooting.md)**.
