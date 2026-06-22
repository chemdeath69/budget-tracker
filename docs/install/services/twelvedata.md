# Service · Twelve Data — security prices   ⬜ OPTIONAL · Free

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

Powers **security price history** and the **change indicators / price charts** on the Investments
page. Without it, your holdings still show their current market value (from Plaid) — you just don't
get the daily price refresh, sparklines, or per-security price charts.

**config key:** `twelvedata.api_key` · **Disable:** leave it `''`

---

## Get a key

### 1. Create a free account

Go to **<https://twelvedata.com/register>** and create an account (Full name / Email / Password, or
**Sign up with Google**). Confirm the verification email if prompted.

### 2. Copy your API key

Open **<https://twelvedata.com/account/api-keys>**. A **Secret key** (a 32-character token) is created
automatically for the free **Basic** plan — copy it from the **Token** column (use **Reveal** if it's
masked).

The **free tier** (currently ~8 requests/minute, ~800/day) is enough — the nightly cron refreshes a
small set of your held securities and is staleness-gated.

## Add it to `config.php`

```php
'twelvedata' => [
    'api_key' => 'YOUR_TWELVEDATA_KEY',   // empty '' = disabled
],
```

Re-upload `config.php`. Prices populate on the next cron run (or trigger one manually — see
[50 · Cron](../50-cron-and-sync.md)).

## Verify

This feed only fetches prices for **securities you actually hold**, so until you've linked a brokerage
there's nothing to price. Confirming the key is accepted is enough: run the cron and check the log —
you should see a clean `prices: 0 close(s) across 0 symbol(s)` (or real counts once you hold
securities) with **no auth error**. Sparklines and per-security price charts on the **Investments**
page appear after a brokerage is linked and the cron has run.

## Notes

- The feed refreshes prices for the securities you actually hold; it backfills history over a few
  runs.
- If a ticker isn't found, that one security just won't get a price — the rest still work.

→ Back to the [main guide](../../INSTRUCTIONS.md#third-party-services)
