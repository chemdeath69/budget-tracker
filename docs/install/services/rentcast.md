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

1. Go to **<https://app.rentcast.io/>** → sign up (free tier).
2. In the dashboard, create / copy your **API key**.
3. Note the free tier: **50 requests/month**. The app refreshes a home value at most ~monthly, well
   within that.

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

## Notes / safety

- The app's monthly cap is enforced in code (`api_usage` counter). Do not remove it.
- There is **no sandbox** — every RentCast call is real and counts against your 50/month. Don't loop
  test calls.
- Address format is `Street, City, State, Zip`. A malformed address may return no value (no charge
  for a normal failed lookup, but it still consumes a request slot).

→ Back to the [main guide](../../INSTRUCTIONS.md#third-party-services)
