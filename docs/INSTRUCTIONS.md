# budget-tracker — Installation Guide

This guide takes you from **nothing** to a **working, signed-in budget-tracker** running on your own
hosting account, with your own bank data flowing in through Plaid.

It is written for a **fresh, independent install**: you create your **own** hosting account,
subdomain, database, and your **own** third-party service accounts. Nothing is shared with anyone
else's deployment.

> **Reference deployment.** The app was originally built and run on a **sureserver**
> (SureSupport custom control panel) shared-hosting account. This guide uses that exact vendor for
> the hosting walkthrough. The app is plain PHP, so any **PHP 8.3 + MySQL 8** host works — but if
> you use a different host, you'll adapt the hosting steps (everything else is identical).

---

## Table of contents

1. [Before you start — what you need](#before-you-start)
2. [Choose your install track](#choose-your-track)
3. [The steps, in order](#the-steps-in-order)
4. [Third-party services](#third-party-services)
5. [After install](#after-install)
6. [Help](#help)

---

<a name="before-you-start"></a>
## 1. Before you start — what you need

Read **[`install/00-overview.md`](install/00-overview.md)** first. It explains how the pieces fit
together, the full shopping list, rough costs, and which features need which third-party keys.

The absolute minimum to get a usable app:

| # | Thing | Required? | Get it from |
|---|---|---|---|
| 1 | A **sureserver** hosting account (or any PHP 8.3 + MySQL 8 host) | ✅ Required | Your hosting provider |
| 2 | A **domain name** (e.g. `example.com`) | ✅ Required | A registrar (or your host) |
| 3 | A **Google Cloud OAuth client** (sign-in) | ✅ Required | [`services/google-oauth.md`](install/services/google-oauth.md) |
| 4 | A **Plaid** account (bank data) | ✅ Required | [`services/plaid.md`](install/services/plaid.md) |
| 5 | Twelve Data key (security prices) | ⬜ Optional · Free | [`services/twelvedata.md`](install/services/twelvedata.md) |
| 6 | FRED key (economic data) | ⬜ Optional · Free | [`services/fred.md`](install/services/fred.md) |
| 7 | Polygon.io key (dividends) | ⬜ Optional · Free | [`services/polygon.md`](install/services/polygon.md) |
| 8 | RentCast key (home value) | ⬜ Optional · **Paid risk** | [`services/rentcast.md`](install/services/rentcast.md) |
| 9 | Anthropic key (OCR + AI assistant) | ⬜ Optional · **Paid** | [`services/anthropic.md`](install/services/anthropic.md) |

You can install with only #1–#4 and add the optional feeds later by editing your config — leaving
an optional key blank simply disables that one feature.

---

<a name="choose-your-track"></a>
## 2. Choose your install track

You'll do the **third-party service signups (Google, Plaid, …) the same way** on both tracks. The
tracks only differ in **how the hosting + files + database get set up**.

### Track A — Guided installer (recommended if you're comfortable in a terminal)

Run **[`tools/install.sh`](../tools/install.sh)**. It asks you a handful of questions (your panel
API key, the subdomain you want, a database password, …) and then calls the sureserver Control
Panel API to do the heavy lifting: create the subdomain, create the MySQL 8 database + user, set
the PHP version, write your `config.php`, import the schema, and create the cron job.

You still do two things by hand: **upload the code once** (one zip in the File Manager) and
**click through the Plaid/Google consoles** (no API exists for those).

→ Full instructions: [`install/10-hosting-sureserver.md` § Guided installer](install/10-hosting-sureserver.md#guided-installer)

### Track B — Manual setup (recommended for most people / no terminal needed)

Click through the hosting control panel and each service console yourself, following the subpages
below. Nothing but a web browser required.

> **Either way, follow the subpages in the order in section 3.** Track A automates the *hosting*
> subpages (10, 30, 40, 50) but you should still read them so you understand what the script did and
> can verify it.

---

<a name="the-steps-in-order"></a>
## 3. The steps, in order

Do these **top to bottom**. Each links to a detailed subpage.

| Step | Subpage | What you do |
|---|---|---|
| 0 | [`install/00-overview.md`](install/00-overview.md) | Understand the architecture + gather your shopping list |
| 1 | [`install/services/google-oauth.md`](install/services/google-oauth.md) | Create the Google sign-in client (do this early — you need the redirect URL, which contains your domain) |
| 2 | [`install/services/plaid.md`](install/services/plaid.md) | Create your Plaid app, enable products, get keys |
| 3 | [`install/10-hosting-sureserver.md`](install/10-hosting-sureserver.md) | Hosting account, subdomain, MySQL 8 DB + user, PHP 8.3 (Track A or B) |
| 4 | [`install/20-upload-code.md`](install/20-upload-code.md) | Get the `www/` files onto the server |
| 5 | [`install/30-config-and-secrets.md`](install/30-config-and-secrets.md) | Create `config.php`, generate encryption keys, fill in your service keys |
| 6 | [`install/40-database-schema.md`](install/40-database-schema.md) | Load the database schema (47 tables) |
| 7 | [`install/50-cron-and-sync.md`](install/50-cron-and-sync.md) | Schedule the nightly sync + run it once |
| 8 | [`install/60-verify.md`](install/60-verify.md) | Sign in, link a bank, smoke-test every page |

> **Why services first?** Your Google redirect URL and Plaid webhook URL both contain your final
> domain (`https://budget.yourdomain.com/oauth-callback.php`). It's fine to register them before the
> site is live — they just need to be correct. If you prefer, set up hosting first (step 3) so you
> *know* your domain, then come back and do the consoles. Either order works; just make sure the
> URLs match before you test sign-in.

---

<a name="third-party-services"></a>
## 4. Third-party services

Each service that appears in `config.php` has its own walkthrough. Open only the ones you want.

| Service | Label | config key | Disable by | Feature you lose if skipped |
|---|---|---|---|---|
| [Google OAuth](install/services/google-oauth.md) | ✅ **Required** | `google` | — (mandatory) | Sign-in (the whole app) |
| [Plaid](install/services/plaid.md) | ✅ **Required** | `plaid` | — (mandatory) | Live bank/credit/investment data |
| [Twelve Data](install/services/twelvedata.md) | ⬜ Optional · Free | `twelvedata.api_key` | leave blank | Security price history + change indicators |
| [FRED](install/services/fred.md) | ⬜ Optional · Free | `fred.api_key` | leave blank | Economic page, inflation-adjusted net worth, refi insight |
| [Polygon.io](install/services/polygon.md) | ⬜ Optional · Free | `polygon.api_key` | leave blank | Dividend calendar + projected income |
| [RentCast](install/services/rentcast.md) | ⬜ Optional · **Paid risk** | `rentcast.api_key` + `home.address` | leave blank | Home-value (AVM) vs. mortgage card |
| [Anthropic (Claude)](install/services/anthropic.md) | ⬜ Optional · **Paid** | `anthropic.api_key` | leave blank | 401(k) statement-photo OCR + the AI assistant |

> ⚠️ **Empty string = disabled.** For every optional key, an **empty** value cleanly turns the
> feature off. A non-empty *placeholder* (like `ENTERKEYHERE`) is treated as a real key and will make
> the nightly job call the provider and log auth errors. Leave it `''` until you have a real key.

---

<a name="after-install"></a>
## 5. After install

- **Add the second user.** Add their email to `allowed_emails` in `config.php` and re-upload it; they
  can then sign in and link their own banks.
- **Back up your `encryption_key`.** Losing it means re-linking every bank.
- **Renew your domain** before it expires, or sign-in URLs break.
- **Upgrades later:** a fresh install loads the complete `schema.sql`. If you later pull new code that
  adds a migration (`www/lib/migrations/NNN_*.php`), run just that migration once — see
  [`install/40-database-schema.md` § Upgrading](install/40-database-schema.md#upgrading).

---

<a name="help"></a>
## 6. Help

Stuck? See **[`install/troubleshooting.md`](install/troubleshooting.md)** — it collects every
hard-won gotcha (MySQL 8 socket/username quirks, the PHP-8.3-CLI cron path, blank 500 pages,
timezone traps, and more).

The raw control-panel API (every endpoint, method, and request body) is in
[`install/reference/sureserver-api.postman_collection.json`](install/reference/sureserver-api.postman_collection.json).
