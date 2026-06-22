# 00 · Overview — how it fits together & what you need

[← Back to the Installation Guide](../INSTRUCTIONS.md)

Read this once before you start. It will save you backtracking.

---

## What you're building

A single small PHP website at a subdomain you choose — e.g. **`https://budget.yourdomain.com`** —
backed by one MySQL 8 database. It:

- signs you in with **Google** (only emails you allow-list),
- pulls your accounts/transactions/balances from **Plaid** on a nightly **cron** job (and on demand),
- stores everything in MySQL and renders pages server-side (no build step, no JavaScript framework),
- optionally enriches the data with a handful of **free or paid APIs**.

```
                                  ┌─────────────────────────────┐
   You (browser)  ──── HTTPS ───▶ │  budget.yourdomain.com      │
   Google sign-in ◀────────────▶ │  (PHP 8.3 in your web root) │
                                  │   www/  + config.php        │
                                  └───────────┬─────────────────┘
                                              │ PDO (socket)
                                  ┌───────────▼─────────────────┐
                                  │  MySQL 8 database           │
                                  └───────────▲─────────────────┘
                                              │
   Nightly cron ─▶ www/cron/sync.php ─────────┘──▶ Plaid, Twelve Data, FRED,
                                                    Polygon, RentCast (outbound API calls)
```

---

## The shopping list

### Required

| Item | Notes | Cost |
|---|---|---|
| **Hosting account** | **any cPanel-based host** with **PHP 8.3 (FPM) + MySQL 8**, SSH or File Manager, and **cron** — tested on [ICDSoft](https://www.icdsoft.com) (sureserver-backed) | Paid hosting plan |
| **Domain name** | You'll point a subdomain at the app. A wildcard SSL cert on sureserver covers `*.yourdomain.com` automatically | ~$10–15/yr |
| **Google Cloud project + OAuth client** | For sign-in. No paid Google APIs are enabled | Free |
| **Plaid account** | The bank-data provider. The **free trial includes Production access** for up to **10 linked institutions** — plenty for a typical household | Free (trial) |

### Optional (each independently skippable — leave its key blank)

| Item | Powers | Cost |
|---|---|---|
| **Twelve Data** | Security price history + Investments change indicators/charts | Free tier |
| **FRED** (St. Louis Fed) | Economic page, inflation-adjusted net worth, mortgage-vs-market refi insight, savings-rate context | Free, no per-request billing |
| **Polygon.io** | Dividend calendar + projected annual income on Investments | Free tier (5 req/min) |
| **RentCast** | Home-value (AVM) vs. mortgage card | ⚠️ Free tier = 50 req/mo, **per-request overage fee above that** (the app hard-caps at 50/mo) |
| **Anthropic (Claude)** | Reads 401(k) statement **photos** into the form + the natural-language **AI assistant** | Pay-as-you-go prepaid credits (a few dollars goes a long way) |

> You can start with just the required four and add optional feeds anytime by editing `config.php`
> and re-uploading it.

---

## Technical requirements (any host)

The preferred setup is **any cPanel-based hosting provider** (the reference deployment was tested on
[ICDSoft](https://www.icdsoft.com), whose servers are sureserver-backed). Whatever host you pick, it
must provide:

- **PHP 8.3** (8.x ≥ 8.1 will likely work; 8.3 is what it's tested on), running as **FPM** ideally.
- These PHP extensions: **`sodium`** (encrypts Plaid tokens — mandatory), **`pdo_mysql`**, **`curl`**,
  **`openssl`**, **`mbstring`**, **`json`**. Verify with `php -m`.
- **MySQL 8** (InnoDB, `utf8mb4`). MySQL 5.7 will *mostly* work but 8 is assumed (JSON columns,
  modern defaults).
- A way to run a **daily cron** calling the PHP 8.3 CLI.
- **HTTPS** (Google + Plaid both refuse plain HTTP redirects/webhooks).
- *(Optional)* **poppler's `pdftotext`** binary — only needed if you upload Webull-style brokerage
  **PDFs** for manual accounts. Path goes in `config.php` (`pdftotext`).

The reference host — [ICDSoft](https://www.icdsoft.com) (sureserver-backed) — provides all of the
above. Notable host-specific quirks you'll meet are collected in
[`troubleshooting.md`](troubleshooting.md); the big ones:

- MySQL 8 is reached via a **non-default socket/port** (`/tmp/mysql8.sock` or `127.0.0.1:3308`), and
  the MySQL **username is short** (e.g. `budget`) while the **database name is prefixed**
  (`youracct_budget`).
- The default `php` CLI is old (5.6); cron must call **`/usr/local/bin/php83.cli`** explicitly.

---

## Roughly how long

| Phase | Time |
|---|---|
| Google OAuth client | 10–15 min |
| Plaid app + products | 15–20 min |
| Hosting (subdomain, DB, PHP) — guided installer | 5–10 min |
| Hosting — fully manual | 20–30 min |
| Upload code + config + schema | 10–15 min |
| Cron + first sync + verify | 10–15 min |
| **Total** | **~60–90 min** |

---

## Next

→ Continue to the services you need, then hosting. The recommended order is in the
[main guide, section 3](../INSTRUCTIONS.md#the-steps-in-order). Most people do **Google → Plaid →
Hosting → Upload → Config → Schema → Cron → Verify**.
