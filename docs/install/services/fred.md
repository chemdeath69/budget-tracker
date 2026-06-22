# Service · FRED (St. Louis Fed) — economic data   ⬜ OPTIONAL · Free

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

Powers the **Economic** page and several inline insights: **inflation-adjusted ("real") net worth**
(CPI), **mortgage-rate-vs-market / refinance** insight (30-yr average), and **savings-rate context**
(Treasury / Fed-funds / national savings rate). Completely **free, no per-request billing**.

**config key:** `fred.api_key` · **Disable:** leave it `''`

---

## Get a key

### 1. Create a free FRED account (or sign in)

Open **<https://fredaccount.stlouisfed.org/apikeys>**. If you're not signed in, FRED shows a
**Sign In / Create New Account** modal. Use the **Create New Account** tab to register with the email
you want this install to own (ignore any "Sign in as …" Google one-tap if it's not the right account).

After registering you'll see a **"New Account Created"** confirmation. (FRED may also email a
verification link — click it if prompted.)

### 2. Request the API key

Go to **<https://fredaccount.stlouisfed.org/apikeys>**. A new account has none yet — click
**+ Request API Key**.

On the request form, **describe the application** (e.g. "Personal finance dashboard — retrieves CPI,
mortgage, Treasury, Fed-funds and savings-rate series"), tick **I have read and agree to the Terms of
Use**, then click **Request API Key**.

### 3. Copy the key

The key (a **32-character hex string**) appears immediately in a banner at the top of the page:
*"Your registered API key is: …"*. Copy it. You can return to the **API Keys** page anytime to view it.

## Add it to `config.php`

```php
'fred' => [
    'api_key' => 'YOUR_FRED_KEY',   // empty '' = disabled
],
```

Re-upload `config.php`. The nightly cron pulls the series (CPI, 30-yr mortgage, 10-yr Treasury,
Fed-funds, national savings rate) and backfills history; the **Economic** page and the real-net-worth
/ refi insights light up after a run.

## Verify

Run the cron once (`php83.cli .../cron/sync.php`) — the log should show
`fred: NNN obs across 5 series` with **no auth error**. Then open the **Economic** page; the five
indicators populate (CPI, 30-Year Fixed Mortgage Rate, 10-Year Treasury Yield, Federal Funds Rate,
National Savings Rate) with sparklines:

## ⚠️ Empty vs. placeholder

`fred.api_key` is treated as **disabled only on an empty string `''`**. A non-empty placeholder (e.g.
`ENTERKEYHERE`) is treated as a real key and the cron will call FRED and log an auth error. Leave it
`''` until you paste a real key.

→ Back to the [main guide](../../INSTRUCTIONS.md#third-party-services)
