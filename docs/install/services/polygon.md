# Service · Polygon.io — dividend calendar   ⬜ OPTIONAL · Free

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

Powers the **"Dividend income & calendar"** section on the Investments page — projected annual
dividend income from your current holdings plus upcoming ex-dividend dates. **Free tier, no
per-request billing** (5 requests/minute; the app is staleness-gated to stay well under that).

**config key:** `polygon.api_key` · **Disable:** leave it `''`

---

> **⚠️ Polygon.io is now "Massive".** Polygon rebranded — **<https://polygon.io/dashboard/keys>**
> redirects to **massive.com**, the account you create is a *Massive* account, and the app already
> calls the free dividends endpoint via host **`api.massive.com`**. A Massive account + key is exactly
> what you need; nothing else changes.

## Get a key

### 1. Create a free account

Open **<https://polygon.io/dashboard/keys>** — you'll land on Massive's **Create your account** page.
Sign up with `Google`, `GitHub`, or `Email`. (If you already have one, click **Sign in**.)

### 2. Copy your API key

You're taken to the **Keys** dashboard, where a **Default** key already exists. Copy the value in the
**Key** column.

The **free tier** allows 5 requests/minute, no per-request billing — the app is staleness-gated to
stay well under that.

## Add it to `config.php`

```php
'polygon' => [
    'api_key' => 'YOUR_POLYGON_KEY',   // empty '' = disabled
],
```

Re-upload `config.php`. The nightly cron refreshes dividend data **at most ~weekly per security**
(staleness-gated so the 5/min free limit is never approached). The dividend section appears on
Investments after a run.

## Verify

Dividends are only fetched for **securities you hold**, so with no brokerage linked yet there's
nothing to pull. Confirming the key is accepted is enough: run the cron and look for a clean
`dividends: 0 refreshed / 0 fresh, 0 row(s) across 0 symbol(s)` (real counts once you hold dividend
payers) with **no auth error**. The "Dividend income & calendar" section on **Investments** populates
after a brokerage is linked and the cron runs.

## ⚠️ Empty vs. placeholder

Like FRED, `polygon.api_key` disables **only on an empty string `''`**. A non-empty placeholder is
treated as a real key (the cron will call Polygon and log an error). Keep it `''` until you have a
real key.

→ Back to the [main guide](../../INSTRUCTIONS.md#third-party-services)
