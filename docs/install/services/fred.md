# Service · FRED (St. Louis Fed) — economic data   ⬜ OPTIONAL · Free

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

Powers the **Economic** page and several inline insights: **inflation-adjusted ("real") net worth**
(CPI), **mortgage-rate-vs-market / refinance** insight (30-yr average), and **savings-rate context**
(Treasury / Fed-funds / national savings rate). Completely **free, no per-request billing**.

**config key:** `fred.api_key` · **Disable:** leave it `''`

---

## Get a key

1. Create a free FRED account: **<https://fredaccount.stlouisfed.org/>**.
2. Go to **<https://fredaccount.stlouisfed.org/apikeys>** → **Request API Key**.
3. Copy the key (a 32-character hex string).

## Add it to `config.php`

```php
'fred' => [
    'api_key' => 'YOUR_FRED_KEY',   // empty '' = disabled
],
```

Re-upload `config.php`. The nightly cron pulls the series (CPI, 30-yr mortgage, 10-yr Treasury,
Fed-funds, national savings rate) and backfills history; the **Economic** page and the real-net-worth
/ refi insights light up after a run.

## ⚠️ Empty vs. placeholder

`fred.api_key` is treated as **disabled only on an empty string `''`**. A non-empty placeholder (e.g.
`ENTERKEYHERE`) is treated as a real key and the cron will call FRED and log an auth error. Leave it
`''` until you paste a real key.

→ Back to the [main guide](../../INSTRUCTIONS.md#third-party-services)
