# Service · Plaid — bank, credit, loan & investment data   ✅ REQUIRED · Free

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

Plaid is the engine that pulls your real accounts (checking, savings, credit cards, loans,
brokerages, retirement) into the app. **Production** access (for real bank data) is free for personal
use up to **10 linked institutions** — more than enough for a typical household — but as of 2025 you
must **request it** (a short questionnaire + a quick review); it is no longer granted automatically on
signup. See **[1. Create a Plaid account](#1-create-a-plaid-account)** below.

**config keys this fills:** `plaid.env`, `plaid.client_id`, `plaid.secret`, `plaid.webhook_url`

> You need your final app URL for the **webhook**: `https://<sub>.<domain>/webhook.php`. Pick your
> subdomain (step 10) before finishing here, or just decide it now.

---

## 1. Create a Plaid account

1. Go to **<https://dashboard.plaid.com/signup>** and create an account (Google SSO works). Use an
   email you control — verification + the access review go there.
2. You'll land on the dashboard at **<https://dashboard.plaid.com>**. **New accounts start in
   Sandbox only.** On **Developers → Keys** you'll see a **Client ID** and a **Sandbox secret**, but
   the **Production secret** reads *"You don't have access"* with a **Request access** button.

   ![Keys page on a fresh account — no Production secret yet](../img/plaid-01-keys.png)

3. **Request Production access** — click **Get full access** (top-left nav, or the **Request access**
   button on the Keys page). Fill out the questionnaire: business + personal details including your
   **address, date of birth, last-4 of SSN, and citizenship**, plus a short product description (e.g.
   *"A personal finance dashboard that aggregates my own household's bank, card, loan and brokerage
   accounts to track spending and net worth."*). Submit it.
4. Plaid puts the request **under review** — the dashboard shows **"Free trial: Pending / Under
   review"** and the access checklist as "X of 3 complete". For a simple personal-use app this is
   typically approved quickly. **Wait for approval before continuing.**

   ![Free trial / production access pending review](../img/plaid-03-production-access-pending.png)

5. Once approved, **Developers → Keys** shows your **Production secret**. Copy the **Client ID** +
   the **Production secret** (you want **Production**, not Sandbox).

   ![Keys page after approval — Production secret issued](../img/plaid-04-keys-production.png)

> **Don't want to wait for approval?** You can deploy immediately against **Sandbox** (`plaid.env =
> sandbox` + the Sandbox secret) and test with Plaid's fake bank (`user_good` / `pass_good`), then
> flip to `production` + the Production secret once it's granted. See the Sandbox tip under
> [step 5](#5-put-the-keys-in-configphp).

## 2. Enable the products the app uses

In the dashboard, make sure these **products** are enabled for your Production environment (under
**Products**, or they may already be on in the trial — each should show enabled/"Trial"):

| Product | Powers in the app |
|---|---|
| **Transactions** | The transaction ledger (up to 24 months of history) |
| **Balance** | Live account balances / net worth |
| **Liabilities** | Credit cards, student loans, mortgages (APR, due dates) |
| **Investments** | Brokerage/retirement holdings, balances, investment transactions |
| **Recurring Transactions** *(add-on)* | The subscriptions / recurring-bills view |
| **Transactions Refresh** *(add-on)* | The on-demand "Refresh now" button + proactive new-transaction sync |

All six are available for US/CA institutions. **Note (current dashboard):** you don't pre-toggle
products on a per-team page — the app **requests the products it needs per `link_token`** at bank-link
time, and Plaid grants them based on your access. The **Products** page just shows what's available
(each reads "Trial" until you're fully live).

![Products available to the account](../img/plaid-06-products.png)

## 3. Get your API keys

1. Dashboard → **Developers → Keys** (<https://dashboard.plaid.com/developers/keys>).
2. Copy your **`client_id`** (same across environments).
3. Copy your **Production `secret`** (there's a separate Sandbox secret — you want **Production**).

> The app uses base URL `https://production.plaid.com` (set automatically when `plaid.env` is
> `production`). All Plaid endpoints are `POST` + JSON.

## 4. Webhook + redirect URI

1. **Webhook — nothing to do in the dashboard.** The app sends `plaid.webhook_url` from `config.php`
   on **every** `link_token`, so Plaid notifies the right instance per linked item automatically (so
   new transactions/holdings show up without waiting for the nightly cron). The dashboard's
   **Developers → Webhooks** page is for *account-level, per-event* webhooks, which this app doesn't
   need — leave it empty. Just make sure `webhook_url` in `config.php` is
   `https://<sub>.<domain>/webhook.php` (see [step 5](#5-put-the-keys-in-configphp)).
2. **Allowed redirect URIs** *(only needed for banks that use an OAuth login flow)* — Dashboard →
   **Developers → API → Allowed redirect URIs → Configure → Add new URI**:
   ```
   https://<sub>.<domain>/link.php
   ```
   If you skip this, non-OAuth banks still link fine; some large banks that require OAuth will need
   it. You can add it later if a bank link fails with a redirect error.

   ![Adding the link.php redirect URI](../img/plaid-05-redirect-uri.png)

> **⚠️ Identity re-verification gate.** Saving a redirect-URI/webhook change pops a *"Verify your
> identity → Verify with Google"* modal — re-authenticate with the Google account you signed up with,
> and the change saves.

## 5. Put the keys in `config.php`

```php
'plaid' => [
    'env'            => 'production',          // 'production' | 'sandbox'
    'client_id'      => 'YOUR_PLAID_CLIENT_ID',
    'secret'         => 'YOUR_PLAID_PRODUCTION_SECRET',
    'webhook_url'    => 'https://<sub>.<domain>/webhook.php',
    'days_requested' => 730,                   // history pulled on first link (max ~24 months)
],
```

> **Want to test without real banks first?** Set `'env' => 'sandbox'` and use your **Sandbox**
> secret. In Plaid Link choose any bank and use credentials `user_good` / `pass_good`. Switch back to
> `'production'` + the Production secret (and re-upload `config.php`) when you're ready for real data.

---

## Good to know

- **Amount sign convention:** Plaid (and this app) use **`+` = money OUT (spending)** and
  **`−` = money IN (income)**. You'll see this reflected in the ledger.
- **Access tokens** (one per linked bank) are stored **encrypted** in your database using your
  `encryption_key`. That's why losing the key means re-linking.
- **10-connection limit:** each *institution* you link counts as one. A bank with 5 accounts is still
  one connection.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Plaid Link won't open | Check `client_id`/`secret` are the **Production** keys and `env` is `production`. Re-upload `config.php`. |
| Bank fails with a redirect/OAuth error | Add `https://<sub>.<domain>/link.php` to **Allowed redirect URIs** in the Plaid dashboard. |
| Linked but no transactions | Normal at first — they arrive on the first webhook/sync. Click **Refresh** or run the cron. |
| Webhook not firing | Confirm the webhook URL is reachable over HTTPS and matches `webhook.php`. The nightly cron is the backstop regardless. |

→ Back to the [main guide](../../INSTRUCTIONS.md#the-steps-in-order). Required services done — the rest are optional.
