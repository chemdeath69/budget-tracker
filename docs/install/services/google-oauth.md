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

## 1. Create a Google Cloud project

1. Go to **<https://console.cloud.google.com/>** and sign in with the Google account that will
   *own* the project (can be either household account).
2. Top bar → project dropdown → **New Project**. Name it e.g. `budget-tracker`. **Create**, then
   select it.

## 2. Configure the OAuth consent screen

1. Left menu → **APIs & Services → OAuth consent screen** (newer consoles: **Branding** under the
   *Google Auth platform*).
2. **User type: External** → **Create**.
3. Fill the required fields:
   - **App name:** `Budget Tracker` (anything)
   - **User support email:** your email
   - **Developer contact email:** your email
   - Logo/links optional — skip.
4. **Scopes:** you don't need to add any restricted scopes. The app requests only
   `openid email profile`, which are the default non-sensitive scopes — no verification needed.
5. **Test users** (while the app is in "Testing"): **Add** every email that will sign in (your
   `allowed_emails`). In Testing mode, only listed test users can complete sign-in — which is exactly
   what you want for a 2-person app, so you can leave it in **Testing** indefinitely.
   - *(Optional)* If you'd rather, click **Publish app** to move to Production. With only
     non-sensitive scopes, Google does **not** require app verification. Either Testing-with-test-users
     or Published works; your `allowed_emails` list is the real gatekeeper.

## 3. Create the OAuth client ID

1. Left menu → **APIs & Services → Credentials**.
2. **+ Create Credentials → OAuth client ID**.
3. **Application type: Web application**. Name it `budget-web`.
4. Under **Authorized redirect URIs**, **+ Add URI**:
   ```
   https://<sub>.<domain>/oauth-callback.php
   ```
   - ⚠️ It must match **exactly** — scheme (`https`), host, path, no trailing slash, correct case.
   - *(Optional)* You can also add `http://localhost/...` only if you test locally; for production
     just the one HTTPS URI.
   - You do **not** need to add an "Authorized JavaScript origin".
5. **Create.** Google shows your **Client ID** (`…apps.googleusercontent.com`) and **Client secret**
   (`GOCSPX-…`). Copy both.

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
