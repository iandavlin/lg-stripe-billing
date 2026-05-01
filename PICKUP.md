# Pickup — lg-stripe-billing

*Last worked: 2026-05-01 (session 7)*

## NEXT — WP plugin: `[lg_regional_fail]` shortcode

The Slim side of regional verification is complete. The only remaining piece is the WP failure-landing page hosted in `lg-patreon-stripe-poller`.

**When billing-country check fails**, Slim redirects the browser to:
```
{APP_REGIONAL_FAIL_URL}?reason=region_mismatch&region_tag=regional_b&billing_country=XX&standard_price_id=price_xxx
```

The WP page at `APP_REGIONAL_FAIL_URL` needs a `[lg_regional_fail]` shortcode that:
1. Reads `standard_price_id` from `$_GET` and renders a "Subscribe at standard pricing" button (links to `[lg_join]` with that price pre-selected, or just the join page)
2. Renders a "Contact support" link
3. Optionally shows a friendly message: "Your billing location ({billing_country}) isn't currently eligible for the regional discount."

**Files to add in `lg-patreon-stripe-poller`:**
- New `[lg_regional_fail]` shortcode class (pattern: `LgRegionalFail.php`)
- Register in the plugin bootstrap
- Add WP page to PROD-CUTOVER.md checklist (already done in Slim repo)

**On dev:** add `APP_REGIONAL_FAIL_URL=https://dev.loothgroup.com/<slug>/` to `.env` and create the WP page. Until then, failures redirect to `APP_HOME_URL`.

---

## What shipped in session 7 (this one)

Everything below committed and pushed. Dev is fully operational with all 6 products and 30 regional countries seeded.

**Setup Intent verification flow (lg-stripe-billing):**
- Migration 005: `products.region_tag` column; old `low_income` test data cleaned up
- `catalog.json`: 4 new products (LITE/PRO × regional_a/regional_b) + 8 prices
- `StripeGateway`: `retrieveSetupIntent`, `retrievePaymentMethod`, `createSubscription`, `detachPaymentMethod`
- `ProductRepository`: `regionTagForPrice`, `countryInRegion`, `standardPriceForTierAndInterval`
- `PdoProductRepository`: `listMembership` rewired to product-level region_tag (regional product wins over standard for same tier ref); `resolvePriceForCountry` simplified to pass-through
- `AdminActionLogRepository` + `PdoAdminActionLogRepository`: audit every verification attempt (pass + fail) to `admin_action_log`
- `CheckoutService.createRegionalSetupSession()`: setup-mode session with metadata
- `ReturnHandler.handleRegionalVerify()`: full pass/fail logic — verify → subscribe or verify → detach PM → redirect
- `CheckoutController`: regional prices routed to setup session; `redirect_url` in result → 302 on `/v1/return`
- `SettingsStore`: `getRegionalFailUrl()` backed by `APP_REGIONAL_FAIL_URL` (falls back to `APP_HOME_URL`)
- `stripe-import-catalog.php`: supports `db_ref` + `region_tag` fields on catalog entries
- `PROD-CUTOVER.md`: full 3-tier regional schema, verification flow diagram, country seed SQL, `[lg_regional_fail]` page checklist entry

**Dev verification:**
- `/v1/products?country=US` → 2 standard products ✓
- `/v1/products?country=IN` → 2 regional_b products ($3/$20 LITE, $6/$40 PRO) ✓
- `/v1/products?country=BR` → 2 regional_a products ($4/$30 LITE, $8/$65 PRO) ✓
- Standard price checkout → `cs_test_b1...` (subscription mode) ✓
- Regional price checkout → `cs_test_c1...` (setup mode) ✓

**DB state on dev (test data + regional seed):**

| customer_id | email | wp_user | notes |
|---|---|---|---|
| 3 | browsertest@ | 1817 fart.mcfartingham | purchased 20-seat gift pack (20 codes in gift_codes) |
| 4 | fartbutt@ | 1818 fartbutt | active sub (sub_1TRUR6…); prior canceled sub in history |
| 5 | stinkbutt@ | 1819 stinkbutt | canceled sub; redeemed TESTCODE0001 (gift entitlement expires 2027-04-29) |

**Products on dev (6 active):**
| stripe_product_id | name | ref | region_tag |
|---|---|---|---|
| prod_RerXcVx8RqqS0P | Looth LITE | looth2 | NULL |
| prod_UR6n4gHKDWgaOI | Looth LITE — Regional A | looth2 | regional_a |
| prod_UR6nHhF0ytWSnc | Looth LITE — Regional B | looth2 | regional_b |
| prod_UQXMEMmbIKNEn6 | Looth PRO | looth3 | NULL |
| prod_UR6n0OONgSFBku | Looth PRO — Regional A | looth3 | regional_a |
| prod_UR6nwsTtTRgpkb | Looth PRO — Regional B | looth3 | regional_b |

---

## State at end of session

Checkout flow map (updated):
```
Browser ─► POST /v1/checkout {price_id, email, country}
              │
              ├─ gift=true             ─► POST /v1/checkout → gift Checkout session → /v1/return
              │                               └─► customer + N gift_codes + gift email
              │
              ├─ standard recurring    ─► POST /v1/checkout → subscription Checkout session → /v1/return
              │                               └─► customer + subscription + entitlement + WP sync
              │
              ├─ standard one-time     ─► POST /v1/checkout → payment Checkout session → /v1/return
              │                               └─► customer + entitlement(expires_at) + WP sync
              │
              └─ regional (region_tag) ─► POST /v1/checkout → SETUP Checkout session → /v1/return
                                              └─► verify billing country vs price_regions
                                                    ├─ PASS: create subscription → entitlement + WP sync
                                                    └─ FAIL: detach PM → 302 → APP_REGIONAL_FAIL_URL
                                                                          + admin_action_log row
```

---

## Two-repo system

| Repo | Lives | Role |
|---|---|---|
| [`lg-stripe-billing`](https://github.com/iandavlin/lg-stripe-billing) (this) | EC2: `/home/ccdev/lg-stripe-billing/` (dev) | Slim user-facing API |
| [`lg-patreon-stripe-poller`](https://github.com/iandavlin/lg-patreon-stripe-poller) | EC2: `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/` | WP plugin: pollers + arbiter + capabilities writer + shortcodes |

## Live endpoints (dev)

| Method | Path | Purpose |
|---|---|---|
| GET | `/health` | Liveness probe |
| GET | `/v1/config` | Returns publishable key |
| GET | `/v1/products` | Active membership products + prices (`?country=XX` for regional) |
| POST | `/v1/checkout` | Create Stripe Checkout session (subscription, gift, one-time, or regional setup) |
| POST | `/v1/portal` | Create Stripe customer portal session |
| GET | `/v1/return` | Stripe redirect handler after checkout |
| POST | `/v1/redeem` | Redeem a gift code → grant entitlement + WP sync |
| POST | `/v1/webhook` | Stripe webhook receiver |

## Webhook endpoint (dev)

- Registered: `we_1TR8nSHg6gcIV22bUqxeVvff`
- URL: `https://dev.loothgroup.com/billing/v1/webhook`
- Events: `product.created`, `product.updated`, `price.created`, `price.updated`, `customer.subscription.updated`, `customer.subscription.deleted`
- Secret: in `.env` as `STRIPE_WEBHOOK_SECRET`
- **TODO:** Add `charge.refunded` to registered events

## Server access

```bash
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
```

## Quick test commands

```bash
# Health
curl -s https://dev.loothgroup.com/billing/health

# Products by country
curl -s 'https://dev.loothgroup.com/billing/v1/products?country=US'   # standard
curl -s 'https://dev.loothgroup.com/billing/v1/products?country=IN'   # regional_b
curl -s 'https://dev.loothgroup.com/billing/v1/products?country=BR'   # regional_a

# Regional setup checkout (returns cs_test_c1... clientSecret)
curl -s -X POST https://dev.loothgroup.com/billing/v1/checkout \
  -H 'Content-Type: application/json' \
  -d '{"price_id":"price_1TSEZsHg6gcIV22bPj46CI94","email":"test@example.com"}'

# Browser test console (subscription + gift + redeem)
open https://dev.loothgroup.com/billing/checkout-test.html

# Redeem a code via curl
curl -s -X POST https://dev.loothgroup.com/billing/v1/redeem \
  -H 'Content-Type: application/json' \
  -d '{"code":"ABCDEFGHIJKL","email":"test@example.com"}'

# Check gift codes
source /home/ccdev/lg-stripe-billing/.env
mysql -h $DB_HOST -u $DB_USER -p$DB_PASSWORD $DB_NAME \
  -e 'SELECT id, code, tier, duration_days, purchased_by, redeemed_by, redeemed_at FROM gift_codes ORDER BY id DESC LIMIT 10;'

# Check regional verification audit log
source /home/ccdev/lg-stripe-billing/.env
mysql -h $DB_HOST -u $DB_USER -p$DB_PASSWORD $DB_NAME \
  -e 'SELECT id, customer_id, action, sub_id, reason, success, created_at FROM admin_action_log WHERE action="regional_verify" ORDER BY created_at DESC LIMIT 10;'

# Trigger WP plugin tick manually
cd /var/www/dev && wp cron event run lgms_poll_tick

# Sync one customer
SECRET=$(grep '^LGMS_SHARED_SECRET=' /home/ccdev/lg-stripe-billing/.env | cut -d= -f2)
curl -s -X POST -H "Content-Type: application/json" -H "X-LGMS-Token: $SECRET" \
  -d '{"customer_id":4}' \
  https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer
```

## Decisions locked in

### Subscription status policy
| Stripe status | Access |
|---|---|
| `active` | Full access to tier |
| `trialing` | Full access to trialing tier |
| `past_due` | Keep access through Stripe retry window |
| `canceled` | Revoke immediately |
| `refunded` | Revoke immediately, all cases |

### Regional pricing model
- 3 tiers: standard (NULL), regional_a, regional_b — each is a separate Stripe product
- `price_regions` maps country codes → region_tag
- `/v1/products?country=XX` returns regional product for that country, or standard if no match
- Regional checkout uses Stripe Setup Intent (no charge during Checkout); billing country verified on return before creating subscription
- Every verification attempt logged to `admin_action_log` (action = `regional_verify`)
- Gifts always use standard products — no regional pricing for gift purchases

### Gift / bulk membership model
- `POST /v1/checkout` with `quantity >= 2` → one-time Stripe payment session
- Price computed server-side: base per-seat × qty × (1 - discount%) using `BULK_DISCOUNT_TIERS` env
- Stripe line item uses **`product_data: {name: "..."}`** — NOT linked to Stripe product
- N gift codes generated in `gift_codes` table on return; emailed to purchaser via WP plugin
- Each code redeemed independently via `POST /v1/redeem`; grants entitlement with `expires_at = now + duration_days`

### Upgrade / downgrade
- Near-instant via `customer.subscription.updated` webhook
- Downgrade takes effect at period end

## .env vars (dev)

```
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
APP_BASE_URL=https://dev.loothgroup.com/billing
APP_BASE_PATH=billing
APP_HOME_URL=https://dev.loothgroup.com
APP_REGIONAL_FAIL_URL=                      # not yet set; falls back to APP_HOME_URL
LGMS_SYNC_URL=https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer
LGMS_GIFT_MAIL_URL=https://dev.loothgroup.com/wp-json/lg-member-sync/v1/send-gift-codes
LGMS_SHARED_SECRET=...
BULK_DISCOUNT_TIERS=10:10,20:20,50:30
DB_HOST=127.0.0.1
DB_NAME=lg_membership
DB_USER=lg_membership
DB_PASSWORD=...
```

## Outstanding issues / TODOs, in priority order

### 1. `[lg_regional_fail]` shortcode (WP plugin) ← NEXT

See "NEXT" section at the top.

### 2. Expiry sweep cron (WP plugin)

Gift code entitlements expire (`expires_at` set on grant). The WP plugin poller needs a sweep:
```sql
-- Find expired gift entitlements
SELECT DISTINCT customer_id FROM entitlements
WHERE source_type = 'gift_code'
  AND expires_at IS NOT NULL
  AND expires_at < NOW()
  AND revoked_at IS NULL;
```
Then `revokeBySource('gift_code', id)` and fire WP sync for each customer.

### 3. `[lg_redeem_gift]` shortcode (WP plugin)

Member-facing gift redemption form. Renders code + email inputs, POSTs to `/billing/v1/redeem`, shows confirmation.

### 4. `charge.refunded` confirmation

Register event in Stripe webhook + confirm the handler revokes immediately. Currently unverified.

### 5. Production cutover

No legacy plugin to migrate — clean greenfield deploy to prod.

## System map

Open `docs/system-map.html` in a browser for the full architecture diagram.
