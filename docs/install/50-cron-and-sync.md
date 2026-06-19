# 50 · Cron & first sync

[← Back to the Installation Guide](../INSTRUCTIONS.md)

The app keeps itself fresh with a **nightly cron job** that runs
[`www/cron/sync.php`](../../www/cron/sync.php). Each run pulls new transactions/balances from Plaid
for every linked bank, refreshes the optional data feeds (prices, dividends, economic data, home
value), writes a net-worth snapshot, and sends any enabled alerts/digests.

> You also get **on-demand** refresh in the app (a "Refresh" button) and Plaid sends **webhooks** to
> `webhook.php` when new data lands — but the nightly cron is the backstop. Set it up.

---

## 1. The exact cron command

```bash
/usr/local/bin/php83.cli /home/<cpuser>/www/<sub>/cron/sync.php >> /home/<cpuser>/www/<sub>/storage/cron.log 2>&1
```

Two things in that line are **not optional** on this host:

1. **`/usr/local/bin/php83.cli`** — the explicit PHP 8.3 CLI. The default `php` is **5.6** and lacks
   `sodium`; the job will fail silently if you use bare `php`.
2. **The log path under the web root** — `…/www/<sub>/storage/cron.log`. The home directory is
   root-owned (`drwxr-x---`), so a log redirect to `~/cron.log` fails with *Permission denied*, and a
   **failed redirect means the whole cron line never runs**. Keep the log inside `storage/` (which is
   web-denied). Create the folder first if needed:
   ```bash
   mkdir -p /home/<cpuser>/www/<sub>/storage
   ```

> An ionCube stderr warning may appear in the log on each run — it's harmless and doesn't affect
> execution. If it bothers you, the warning is cosmetic only.

---

## 2. Schedule it (control panel)

1. Panel → **Cron Jobs** (`/crons`).
2. **Add a cron job**, schedule **once daily** at a quiet hour (e.g. **~3:00 AM**):
   | Field | Value |
   |---|---|
   | Minute | a slot the panel offers (this host restricts cron minutes to fixed slots, e.g. **:13 / :28 / :43 / :58**) |
   | Hour | `3` |
   | Day | `*` |
   | Month | `*` |
   | Weekday | `*` |
   | Command | *(the full command from step 1)* |
3. Save.

> **Simplest path:** in the panel's cron dialog leave **Advanced Mode off** and pick the **"Every
> day"** preset — it produces a once-daily schedule (the host fills in the minute slot, e.g.
> `13 1 * * *`). You don't need to set an explicit time.

![Scheduling the cron job (basic mode, "Every day")](img/cron-01-schedule-form.png)

> ⚠️ **This host does not allow an arbitrary minute** (e.g. `0`) — it slots cron jobs to specific
> minutes. The panel UI will only offer the allowed minutes; just pick one.

> **Verify it saved via the API, not just the toast:** `GET /api/v1/crons` lists every cron. (On this
> host the panel stores crons in its own location, so `crontab -l` over SSH may show **nothing** even
> though the cron exists — the **API is the authoritative check**.)

> **API equivalent** — `POST /api/v1/crons/create` with
> `minute=*/60`, `hour=3`, `day=*`, `month=*`, `weekday=*`, and `command=<the full command>`.
> (The API **rejects `minute=0`** with `crons.invalid_time`; use the step form `*/60` and the host
> assigns the actual minute slot.) The **guided installer** creates this for you.

> The reference deployment runs around **01:00–01:15 server time**; any daily slot is fine. Once a
> day is plenty — Plaid data doesn't change minute-to-minute, and webhooks cover same-day activity.

---

## 3. Run it once now (recommended)

Don't wait until 3 AM to find out it works. Trigger it manually:

- **Panel:** on the Cron Jobs page, use **Run now** next to your job
  (API: `POST /api/v1/crons/run`).
- **SSH:**
  ```bash
  /usr/local/bin/php83.cli /home/<cpuser>/www/<sub>/cron/sync.php
  ```

On a brand-new install with **no banks linked yet**, the run will simply do nothing much (no Items
to sync) and exit cleanly — that's the expected "it works" result. After you link a bank in
[step 60](60-verify.md), run it again (or click the in-app **Refresh**) to pull data immediately.

### Read the log

```bash
tail -n 50 /home/<cpuser>/www/<sub>/storage/cron.log
```

You want to see the run start/finish lines with no PHP fatal errors. (The app also records each
nightly run + per-step output in the database, visible on the **Settings → Activity / Sync status**
page once you're signed in.)

---

## ✅ Checkpoint

- [ ] A daily cron entry exists calling `php83.cli … cron/sync.php` with the log under `storage/`,
- [ ] `storage/` exists and `storage/cron.log` gets written when you run the job,
- [ ] a manual run completes without a fatal error.

→ Next: **[60 · Verify everything works](60-verify.md)**.
