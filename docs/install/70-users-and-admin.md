# 70 · Users, roles & admin — first run, inviting people, factory reset

[← Back to the Installation Guide](../INSTRUCTIONS.md)

This page covers everything about **who can sign in** and **who can manage the app** once it's
installed: the first-run "become the administrator" flow, inviting and removing people, the two
roles (admin / member), the Google sign-in caveat for new emails, and the **Factory Reset**.

> **New in this version.** Earlier installs listed who could sign in in `config.php`
> (`allowed_emails`). That list now lives **in the app** and is managed from a Settings page — no
> file editing to add or remove a person. If you're upgrading, see
> [§7 below](#upgrading-from-the-old-allowed_emails-list).

---

## 1. First run — you become the administrator

On a brand-new install the database has **no users yet**. The very first thing you do is sign in:

1. Open **`https://<sub>.<domain>`**. The login page shows a **"First-time setup — become the
   administrator"** welcome (instead of the normal "sign in to continue").
2. Click **Continue with Google** and pick the account you want to own the app.
3. That first sign-in is **automatically allowed and made an administrator**. There's nothing to
   pre-configure — the first person through the door sets up the install.
4. You land on a **Setup checklist** (`setup.php`) that walks you through the next steps:
   invite your household, link a bank, add manual / 401(k) / vehicle accounts, add your home, and
   go to the dashboard. None of it is mandatory — you can do any of it later from **Settings**.

> **Why this is safe.** After that first login the door closes: from then on **only people an admin
> has invited can sign in**. A random Google account hitting your URL is rejected.

---

## 2. The two roles

| Role | Can do |
|---|---|
| **Admin** | Everything a member can, **plus**: invite / remove people, change roles, and run a **Factory Reset**. |
| **Member** | Use the whole app — link their own banks, see shared data, set their account visibility, change their own theme/dashboard. **Cannot** manage other users or factory-reset. |

A typical household has **one or two admins**. Everyone else can be a member. There must always be
**at least one admin** — the app won't let you remove the last one.

---

## 3. Inviting & removing people (Settings → Users & access)

As an admin, go to **Settings → Users & access → Manage users** (page: `users.php`).

### Invite someone

1. Type their **Google email** in the **Invite a user** box, choose **Member** or **Admin**, click
   **Invite**.
2. They appear in the list tagged **pending** until the first time they actually sign in.
3. ⚠️ **One more step for a brand-new email** — see [§4](#4-the-google-sign-in-caveat-important).

### The roster

Each person's row shows their **role**, **status**, and **last login**, plus small tags:

- **you** — your own account (you can't lock yourself out).
- **config admin** — an account that's also in the server's break-glass list (see [§6](#6-break-glass-the-config-admin-tag)). Protected from changes here.
- **pending** — invited but has never signed in yet.
- **disabled** — access has been removed (data kept).

### Change a role

Use the **role dropdown** on a person's row (Admin ↔ Member). You can't demote yourself, a config
admin, or the last remaining admin.

### Remove access (keep their data)

Click **Remove access**. They can **no longer sign in**, but **all their accounts, transactions and
history are kept** — re-enable them any time with **Enable**. This is the right choice for someone
who's leaving but whose shared data you still want in the household totals.

### Delete a pending invite

If you invited the wrong email and they've **never signed in (and own nothing)**, a **Delete**
button removes the invite entirely. Once someone has signed in or linked an account, you can only
**Remove access** (disable), not delete — so their data is never silently destroyed.

---

## 4. The Google sign-in caveat (important)

Inviting an email in the app lets them in **on our side**. But **Google** also has to let their
account *reach* our sign-in. This depends on how your Google OAuth consent screen is configured (see
[`services/google-oauth.md`](services/google-oauth.md)):

- **Consent screen in "Testing" mode** (the default) → you must **also** add the new person's email
  as a **Test user** in Google Cloud Console (**APIs & Services → OAuth consent screen → Test
  users**). Testing mode allows up to 100 test users, so a household is fine here indefinitely.
- **Consent screen "In production"** → no per-email step in Google is needed; inviting them in the
  app is all it takes. Our sign-in only requests basic **email + profile** scopes, so Google does
  **not** require app verification — publishing "In production" is a safe one-time action.

> **Symptom if you forget:** the new person gets a Google "app hasn't verified / access blocked" or
> bounce **before** they ever reach our app. Fix = add them as a Test user, or publish In
> production. (If they reach our app and are then told "not authorised", that's the *app* allowlist
> — invite them in **Users & access**.)

The Users & access page repeats this reminder right under the invite box.

---

## 5. Factory Reset (admin only)

**Settings → Danger zone → Factory reset** wipes the app back to a clean, empty state — useful for
handing the install to someone else, recovering from a bad import, or starting over.

### What it does

1. **Unlinks every bank at Plaid** (`/item/remove`) so they stop syncing and stop counting toward
   your Plaid billing.
2. **Permanently deletes all financial data** — accounts, transactions, holdings, investments,
   liabilities, budgets, goals, rules, custom categories, the home setup, vehicles, credit-report
   imports, net-worth history, everything.
3. **Clears uploaded manual documents** (e.g. Webull/401(k) statement files) from disk.
4. **Re-seeds the empty defaults** and writes a fresh **$0** net-worth snapshot.

### What it KEEPS

- **Sign-in accounts & roles** (you stay signed in, still an admin),
- **Personal preferences** (your theme + customized dashboard),
- **Audit & log tables** (access logs, sync history),
- **Notification settings** (the alert toggles),
- **The economic-data cache** (FRED — it's just public market data).

### How to run it

1. Type **`FACTORY RESET`** exactly into the confirm box (it won't proceed otherwise).
2. Click **Factory reset** and confirm the browser prompt.
3. If a bank **couldn't be unlinked at Plaid** (e.g. the token was already revoked there), you're
   asked whether to **reset locally anyway**. If you say yes, remove those banks yourself in your
   **Plaid dashboard** to be sure billing stops.
4. On success you're dropped back on the **Setup checklist** to start fresh.

> ⚠️ **This cannot be undone.** There is no separate confirmation email or backup — once it runs,
> the financial data is gone. (Your users, roles, and logs survive, so you don't have to re-invite
> anyone.)

---

## 6. Break-glass: the "config admin" tag

The server's `config.php` still has an **`allowed_emails`** list, but its job has changed. It's now
a **break-glass safety net**, not the everyday allowlist:

- Any email in `allowed_emails` can **always** sign in and is **always an admin** — even if someone
  accidentally removed or demoted them in the UI (the app re-grants admin on their next login).
- Those accounts are **protected** in **Users & access** (you can't demote, disable, or delete
  them).
- They show the **config admin** tag in the roster.

Keep at least your own email there so you can never be locked out of your own install. Everyone
else can be managed entirely from the app — no file editing.

---

## 7. Upgrading from the old `allowed_emails` list

If you're updating an existing install that used `config.php`'s `allowed_emails` as the allowlist:

1. **Run the database migration** that adds roles (`032_user_roles.php`) **before** deploying the
   new code — see [`40 · Upgrading`](40-database-schema.md#upgrading). The migration **seeds every
   address already in `allowed_emails` as an admin**, so nobody loses access.
2. Deploy the code.
3. From then on, manage people in **Settings → Users & access**. You can leave `allowed_emails` as
   your break-glass list ([§6](#6-break-glass-the-config-admin-tag)) — typically just your own
   email — and stop editing the file to add/remove others.

> Maintainers of the reference deployment: the exact promote-to-production steps are in the
> gitignored `docs/install/PROMOTE-bootstrap-reset.local.md`.

---

## Quick reference

| I want to… | Where |
|---|---|
| Be the admin on a fresh install | Just sign in first — it's automatic ([§1](#1-first-run--you-become-the-administrator)) |
| Invite a household member | Settings → Users & access → Invite ([§3](#3-inviting--removing-people-settings--users--access)) |
| Let a brand-new email actually reach Google sign-in | Add them as a Google Test user, or publish In production ([§4](#4-the-google-sign-in-caveat-important)) |
| Remove someone but keep their data | Settings → Users & access → **Remove access** ([§3](#3-inviting--removing-people-settings--users--access)) |
| Make someone an admin | Role dropdown on their row ([§3](#3-inviting--removing-people-settings--users--access)) |
| Wipe all financial data and start over | Settings → Danger zone → **Factory reset** ([§5](#5-factory-reset-admin-only)) |
| Never get locked out | Keep your email in `config.php` `allowed_emails` ([§6](#6-break-glass-the-config-admin-tag)) |

→ Back to the [main guide](../INSTRUCTIONS.md) · the sign-in service: [Google OAuth](services/google-oauth.md)
