# Service · Polygon.io — dividend calendar   ⬜ OPTIONAL · Free

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

Powers the **"Dividend income & calendar"** section on the Investments page — projected annual
dividend income from your current holdings plus upcoming ex-dividend dates. **Free tier, no
per-request billing** (5 requests/minute; the app is staleness-gated to stay well under that).

**config key:** `polygon.api_key` · **Disable:** leave it `''`

---

## Get a key

1. Go to **<https://polygon.io/>** → **Sign up** (free).
2. Open **<https://polygon.io/dashboard/keys>**.
3. Copy your **API key**.

> The app calls Polygon's free dividends endpoint via host `api.massive.com` (Polygon's free
> "Massive" tier) — no extra setup, just the key.

## Add it to `config.php`

```php
'polygon' => [
    'api_key' => 'YOUR_POLYGON_KEY',   // empty '' = disabled
],
```

Re-upload `config.php`. The nightly cron refreshes dividend data **at most ~weekly per security**
(staleness-gated so the 5/min free limit is never approached). The dividend section appears on
Investments after a run.

## ⚠️ Empty vs. placeholder

Like FRED, `polygon.api_key` disables **only on an empty string `''`**. A non-empty placeholder is
treated as a real key (the cron will call Polygon and log an error). Keep it `''` until you have a
real key.

→ Back to the [main guide](../../INSTRUCTIONS.md#third-party-services)
