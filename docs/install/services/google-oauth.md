# Service · Google OAuth — sign-in   ✅ REQUIRED · Free

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

The app has **no passwords** — everyone signs in with Google. On a fresh install the **first**
account to sign in becomes the administrator; after that, only people an admin **invites in the app**
(Settings → Users & access) are admitted, with your `config.php` `allowed_emails` as a break-glass
override (see [70 · Users & admin](../70-users-and-admin.md)). You need a **Google Cloud OAuth 2.0
Web client** (its client ID + secret). No paid Google API is enabled; sign-in identity comes from
the OpenID `id_token`.

**config keys this fills:** `google.client_id`, `google.client_secret`, `google.redirect_uri`

> You need to know your final app URL first, because the **redirect URI** contains your domain:
> **`https://<sub>.<domain>/oauth-callback.php`**. If you haven't picked your subdomain yet, do
> [step 10](../10-hosting-sureserver.md) first (or just decide the subdomain now — you can register
> the URI before the site is live).

---

> **⚠️ Newer console layout (2025+).** Google has consolidated the old "OAuth consent screen" and
> "Credentials" pages under **APIs & Services → Google Auth Platform** (`/auth/overview`). The steps
> below describe that newer flow; older screenshots online may show separate pages, but the fields are
> the same (project → app info → audience → client).

## 1. Create a Google Cloud project

1. Go to **<https://console.cloud.google.com/>** and sign in with the Google account that will
   *own* the project (can be either household account).
2. Top bar → project dropdown → **New Project**. Name it e.g. `budget-tracker`. **Create**, then
   select it.

## 2. Configure the OAuth consent screen (Google Auth Platform)

1. Left menu → **APIs & Services → Google Auth Platform → Overview** (`/auth/overview`) → **Get
   started**. This opens a short wizard:
2. **App Information** — **App name** `Budget Tracker` (anything) + **User support email** (pick your
   address from the dropdown). **Next.**

3. **Audience** — choose **External** (Internal is only for Google Workspace orgs). **Next.**

4. **Contact Information** — your email. **Next.**
5. **Finish** — tick the **Google API Services: User Data Policy** agreement → **Continue** →
   **Create.** The app starts in **Testing** mode, which is exactly what you want.
6. **Scopes/Data Access:** nothing to add. The app requests only `openid email profile` (default
   non-sensitive scopes) — **no verification needed**.
7. **Test users** — left menu → **Audience** → **Test users → Add users**: add **every** email that
   will sign in. In Testing mode only listed test users can complete Google sign-in — fine for a
   small household app (Google's Testing mode allows up to 100 test users), so you can leave it in
   **Testing** indefinitely. ⚠️ **Whenever you invite a new person in the app, also add their email
   here** (or publish to Production once, below) — otherwise Google blocks them before they reach the
   app. See [`70-users-and-admin.md` §4](../70-users-and-admin.md#4-the-google-sign-in-caveat-important).
   - *(Optional)* **Publish app** moves to Production; with only non-sensitive scopes Google does
     **not** require verification, and then you don't need to add each email as a test user. Either
     way, the app's own user list (Settings → Users & access) is the real gatekeeper — Google just
     controls who can *reach* the sign-in.

## 3. Create the OAuth client ID

1. Left menu → **Google Auth Platform → Clients** (`/auth/clients`) → **Create client** (older
   consoles: **APIs & Services → Credentials → + Create Credentials → OAuth client ID**).
2. **Application type: Web application**. Name it `budget-web`.
3. Under **Authorized redirect URIs**, **+ Add URI**:
   ```
   https://<sub>.<domain>/oauth-callback.php
   ```
   - ⚠️ It must match **exactly** — scheme (`https`), host, path, no trailing slash, correct case.
   - ⚠️ **Use the right "Add URI" button.** The client page has **two** sections — **Authorized
     JavaScript origins** (top) and **Authorized redirect URIs** (below) — each with its own
     **+ Add URI**. Put the callback under **Authorized redirect URIs**. A full URL with a path
     (`…/oauth-callback.php`) pasted into the *origins* box is silently rejected (origins are
     scheme+host only), so the change won't save and sign-in later fails with `redirect_uri_mismatch`.
   - *(Optional)* You can also add `http://localhost/...` only if you test locally; for production
     just the one HTTPS URI.
   - You do **not** need to add an "Authorized JavaScript origin".
   - **Reusing one client for multiple instances?** A single OAuth client can hold several redirect
     URIs — just **+ Add URI** another `https://<other-sub>.<domain>/oauth-callback.php` to the same
     client (and add each sign-in email as a Test user). Click **Save**, then reload the client to
     confirm the new URI persisted under **Authorized redirect URIs**.

4. **Create.** Google shows your **Client ID** (`…apps.googleusercontent.com`) and **Client secret**
   (`GOCSPX-…`).

> ### ⚠️ The client secret is shown only ONCE (new-console gotcha)
> On the current console the **Client secret is displayed in full only at creation** — in the
> "OAuth client created" dialog, via **Download JSON** (or the copy button). **Copy it immediately.**
> Afterward the Clients → *(your client)* → **Additional information** panel shows only `••••last4`
> with *"Viewing and downloading client secrets is no longer available."* If you miss it, open the
> client → **Additional information** → **Add client secret** to mint a fresh secret (copy it at
> once), then optionally disable + delete the old one. Put the secret in `config.php` (never commit it).

## 4. Put them in `config.php`

```php
'google' => [
    'client_id'     => '1234567890-abcdef.apps.googleusercontent.com',
    'client_secret' => 'GOCSPX-your-secret',
    'redirect_uri'  => 'https://<sub>.<domain>/oauth-callback.php',
],
```

…and put **your own** email in `allowed_emails` as the break-glass admin (see
[30 · Config](../30-config-and-secrets.md)). Everyone else you **invite in the app** after first
sign-in — [70 · Users & admin](../70-users-and-admin.md).

---

## How it works (for reference)

- The app sends you to `accounts.google.com/o/oauth2/v2/auth` with scope `openid email profile`.
- Google redirects back to `oauth-callback.php?code=…`; the app exchanges the code at
  `oauth2.googleapis.com/token`, reads your email from the `id_token`, and checks it against the
  in-app user list (with the first-login bootstrap + `allowed_emails` break-glass on top). No People
  API or other Google API is called.

## Troubleshooting

| Symptom | Fix |
|---|---|
| **Error 400: redirect_uri_mismatch** | The console URI ≠ `config.php` `redirect_uri`. Make them byte-identical (no trailing slash, `https`, exact host). |
| **"Access blocked: app not verified"** | You're Published with sensitive scopes — you shouldn't be. Ensure only `openid email profile`. Or stay in **Testing** and add test users. |
| **Signs in but bounced to login ("not authorised")** | On a fresh install, the first login is auto-admitted — if it's rejected the DB isn't empty. On an existing install, an admin must **invite** that email (Settings → Users & access), or it differs (e.g. `googlemail.com` vs `gmail.com`). The break-glass `allowed_emails` in `config.php` always works. |
| Changed the secret | Update `config.php` and re-upload; old secret stops working immediately. |

→ Back to the [main guide](../../INSTRUCTIONS.md#the-steps-in-order) · next service: [Plaid](plaid.md)
