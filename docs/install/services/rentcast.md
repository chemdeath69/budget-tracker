# Service · RentCast — home value (AVM)   ⬜ OPTIONAL · ⚠️ Paid risk

[← Back to the Installation Guide](../../INSTRUCTIONS.md) · [Service index](../../INSTRUCTIONS.md#third-party-services)

Powers the **home-value vs. mortgage** card (an automated valuation estimate for a property you own,
shown against your linked mortgage balance, plus property/market detail on the Property page).

**config keys:** `rentcast.api_key` **and** `home.address` · **Disable:** leave either `''`

> ⚠️ **Billing caution.** RentCast's free tier is **50 requests/month**, with a **per-request overage
> fee charged automatically above that**. The app **hard-caps usage at 50/month** (a counter reserves
> a slot before every call and refuses past 50 — see `www/lib/home_value.php`), so overage charges
> can't occur through normal use. Still: this is the one feed that *can* cost money if misused, so
> read this page.

---

## Get a key

### 1. Create an account

Go to **<https://app.rentcast.io/>** and **Sign up for free** (a Sign In modal opens — use the
"Sign up for free." link). Sign in afterward.

![RentCast Sign In / Sign up](../img/rentcast-01-signin.png)

### 2. Open the API Dashboard and create a key

In the top nav, open **API Dashboard**. A new account has no keys yet — click **Create API Key**.

![RentCast API Dashboard — no keys yet](../img/rentcast-02-api-dashboard.png)

Give the key a **Name** (e.g. `example-instance`) and click **Create API Key** in the dialog. The full key
value is shown here — copy it.

![RentCast — New API Key dialog](../img/rentcast-03-new-key.png)

### 3. ⚠️ Activate the free "developer" plan (card required)

The key is created but shows **Inactive** until you choose a billing plan.

![RentCast — key created but Inactive](../img/rentcast-04-api-key-created.png)

Under **API Billing**, click **Select Plan** → choose **developer** (**$0/month**, 50 requests,
$0.20/request *only* past 50). RentCast still requires a **credit/debit card on file** and accepting
the terms before it will activate, even though **"Total billed now: $0.00"**.

![RentCast — select the $0/month developer plan](../img/rentcast-05-select-plan.png)

Enter your card, tick the Terms checkbox, and click **Activate Plan**. The key then shows **Active**
and the plan reads **"API developer - active · $0.00/month"**. Because the app **hard-caps at 50
calls/month** and refreshes a home value at most ~monthly, you stay on the $0 tier and the
$0.20/request overage is never reached.

## Add it to `config.php`

```php
'rentcast' => [
    'api_key' => 'YOUR_RENTCAST_KEY',   // empty '' = disabled (and no calls are ever made)
],
'home' => [
    'address' => '123 Main St, Springfield, IL, 62704',   // "Street, City, State, Zip"; blank = disabled
],
```

**Both** must be set for the feature to run. If either is blank, no RentCast calls are made and the
home-value card simply doesn't appear.

Re-upload `config.php`. The nightly cron fetches the valuation ~monthly (and on first run); the
Property page + dashboard home-equity card populate after that.

## Verify

Run the cron once — the log should show `home value: stored $NNN,NNN — quota 1/50 this month`. Then
open the **Property** page: the **Home value** estimate, value chart, property details, and local
market stats populate from RentCast:

![Property page populated from RentCast](../img/rentcast-06-property-verified.png)

## Notes / safety

- The app's monthly cap is enforced in code (`api_usage` counter). Do not remove it.
- There is **no sandbox** — every RentCast call is real and counts against your 50/month. Don't loop
  test calls.
- Address format is `Street, City, State, Zip`. A malformed address may return no value (no charge
  for a normal failed lookup, but it still consumes a request slot).

→ Back to the [main guide](../../INSTRUCTIONS.md#third-party-services)
