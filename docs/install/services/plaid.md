# Service · Plaid — bank, credit, loan & investment data   ✅ REQUIRED · Free trial

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

Plaid is the engine that pulls your real accounts (checking, savings, credit cards, loans,
brokerages, retirement) into the app. The **free trial includes Production access** for up to **10
linked institutions** — more than enough for a 2-person household.

**config keys this fills:** `plaid.env`, `plaid.client_id`, `plaid.secret`, `plaid.webhook_url`

> You need your final app URL for the **webhook**: `https://<sub>.<domain>/webhook.php`. Pick your
> subdomain (step 10) before finishing here, or just decide it now.

---

## 1. Create a Plaid account

1. Go to **<https://dashboard.plaid.com/signup>** and create an account (Google SSO works).
2. You'll land on the dashboard at **<https://dashboard.plaid.com>**. You're on a **free trial** with
   **Production** access enabled (a "connection" = one linked institution; the trial allows up to 10).

> You do **not** need to request "full production access" / fill out the company questionnaire for a
> 2-person personal install — the trial's 10 Production connections are enough. (You'd only do that to
> scale beyond 10 or leave the trial.)

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

All six are available on the trial without extra approval for US/CA institutions.

## 3. Get your API keys

1. Dashboard → **Developers → Keys** (<https://dashboard.plaid.com/developers/keys>).
2. Copy your **`client_id`** (same across environments).
3. Copy your **Production `secret`** (there's a separate Sandbox secret — you want **Production**).

> The app uses base URL `https://production.plaid.com` (set automatically when `plaid.env` is
> `production`). All Plaid endpoints are `POST` + JSON.

## 4. Set the webhook (and redirect URI for OAuth banks)

1. Dashboard → **Developers → API** (or **Webhooks** / **Allowed redirect URIs** section).
2. **Webhook URL** — add:
   ```
   https://<sub>.<domain>/webhook.php
   ```
   This lets Plaid notify the app the moment new transactions/holdings are available (so data shows
   up without waiting for the nightly cron). The app also passes this per `link_token`, but setting
   it here is good practice.
3. **Allowed redirect URIs** *(only needed for banks that use an OAuth login flow)* — add the page
   where Plaid Link runs in the app:
   ```
   https://<sub>.<domain>/link.php
   ```
   If you skip this, non-OAuth banks still link fine; some large banks that require OAuth will need
   it. You can add it later if a bank link fails with a redirect error.

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
