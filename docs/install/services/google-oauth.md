# Service · Google OAuth — sign-in   ✅ REQUIRED · Free

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

The app has **no passwords** — everyone signs in with Google, and only the emails in your
`allowed_emails` list are admitted. You need a **Google Cloud OAuth 2.0 Web client** (its client ID +
secret). No paid Google API is enabled; sign-in identity comes from the OpenID `id_token`.

**config keys this fills:** `google.client_id`, `google.client_secret`, `google.redirect_uri`

> You need to know your final app URL first, because the **redirect URI** contains your domain:
> **`https://<sub>.<domain>/oauth-callback.php`**. If you haven't picked your subdomain yet, do
> [step 10](../10-hosting-sureserver.md) first (or just decide the subdomain now — you can register
> the URI before the site is live).

---

> **⚠️ Newer console layout (2025+).** Google has consolidated the old "OAuth consent screen" and
> "Credentials" pages under **APIs & Services → Google Auth Platform** (`/auth/overview`). The steps
> below describe that newer flow; older screenshots online may show separate pages, but the fields are
> the same (project → app info → audience → client). The screenshots here are from a real run.

## 1. Create a Google Cloud project

1. Go to **<https://console.cloud.google.com/>** and sign in with the Google account that will
   *own* the project (can be either household account).
2. Top bar → project dropdown → **New Project**. Name it e.g. `budget-tracker`. **Create**, then
   select it.

![New Project form](../img/google-01-new-project.png)

## 2. Configure the OAuth consent screen (Google Auth Platform)

1. Left menu → **APIs & Services → Google Auth Platform → Overview** (`/auth/overview`) → **Get
   started**. This opens a short wizard:
2. **App Information** — **App name** `Budget Tracker` (anything) + **User support email** (pick your
   address from the dropdown). **Next.**

   ![App Information step](../img/google-02-app-info.png)

3. **Audience** — choose **External** (Internal is only for Google Workspace orgs). **Next.**

   ![Audience → External](../img/google-03-audience-external.png)

4. **Contact Information** — your email. **Next.**
5. **Finish** — tick the **Google API Services: User Data Policy** agreement → **Continue** →
   **Create.** The app starts in **Testing** mode, which is exactly what you want.
6. **Scopes/Data Access:** nothing to add. The app requests only `openid email profile` (default
   non-sensitive scopes) — **no verification needed**.
7. **Test users** — left menu → **Audience** → **Test users → Add users**: add **every** email that
   will sign in (your `allowed_emails`). In Testing mode only listed test users can complete sign-in —
   fine for a small household app (Google's Testing mode allows up to 100 test users), so you can leave
   it in **Testing** indefinitely.
   - *(Optional)* **Publish app** moves to Production; with only non-sensitive scopes Google does
     **not** require verification. Either way, your `allowed_emails` list is the real gatekeeper.

   ![Adding test users](../img/google-06-test-users.png)

## 3. Create the OAuth client ID

1. Left menu → **Google Auth Platform → Clients** (`/auth/clients`) → **Create client** (older
   consoles: **APIs & Services → Credentials → + Create Credentials → OAuth client ID**).
2. **Application type: Web application**. Name it `budget-web`.
3. Under **Authorized redirect URIs**, **+ Add URI**:
   ```
   https://<sub>.<domain>/oauth-callback.php
   ```
   - ⚠️ It must match **exactly** — scheme (`https`), host, path, no trailing slash, correct case.
   - *(Optional)* You can also add `http://localhost/...` only if you test locally; for production
     just the one HTTPS URI.
   - You do **not** need to add an "Authorized JavaScript origin".

   ![Creating the Web application client + redirect URI](../img/google-04-create-web-client.png)

4. **Create.** Google shows your **Client ID** (`…apps.googleusercontent.com`) and **Client secret**
   (`GOCSPX-…`).

   ![Client created — copy or Download JSON NOW](../img/google-05-client-created.png)

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

…and make sure each sign-in email is in `allowed_emails` (see
[30 · Config](../30-config-and-secrets.md)).

---

## How it works (for reference)

- The app sends you to `accounts.google.com/o/oauth2/v2/auth` with scope `openid email profile`.
- Google redirects back to `oauth-callback.php?code=…`; the app exchanges the code at
  `oauth2.googleapis.com/token`, reads your email from the `id_token`, and checks it against
  `allowed_emails`. No People API or other Google API is called.

## Troubleshooting

| Symptom | Fix |
|---|---|
| **Error 400: redirect_uri_mismatch** | The console URI ≠ `config.php` `redirect_uri`. Make them byte-identical (no trailing slash, `https`, exact host). |
| **"Access blocked: app not verified"** | You're Published with sensitive scopes — you shouldn't be. Ensure only `openid email profile`. Or stay in **Testing** and add test users. |
| **Signs in but bounced to login** | The Google email isn't in `allowed_emails` (or differs, e.g. `googlemail.com` vs `gmail.com`). Use the exact address and re-upload `config.php`. |
| Changed the secret | Update `config.php` and re-upload; old secret stops working immediately. |

→ Back to the [main guide](../../INSTRUCTIONS.md#the-steps-in-order) · next service: [Plaid](plaid.md)
