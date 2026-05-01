# Pickup — lg-stripe-billing

*Last worked: 2026-05-01 (session 7)*

## NEXT — `[lg_regional_fail]` shortcode + cosmetic local-currency display

The Slim regional verification flow is complete and live-tested. Two related polish items remain:

1. **`[lg_regional_fail]` shortcode** in `lg-patreon-stripe-poller` to render a proper failure landing page (currently the test console handles this on dev). When verification fails, Slim 302s the browser to `APP_REGIONAL_FAIL_URL` with query params:
   ```
   ?reason=region_mismatch&region_tag=regional_b&billing_country=XX&issuer_country=YY&standard_price_id=price_xxx
   ```
   Shortcode reads `standard_price_id` and renders a "Subscribe at standard pricing" button + contact-support link. Pattern: `LgRegionalFail.php` → register in plugin bootstrap → set `APP_REGIONAL_FAIL_URL` in prod `.env` to the page slug.

2. **Cosmetic local-currency display** on `[lg_join]` to obscure the regional discount from looky-loos. Static FX table in WP plugin renders `≈ ₹250/mo` next to regional prices. Stripe still charges USD, customer's bank does FX. Decided against actual local-currency Stripe prices (FX revenue risk explicitly rejected). Disclaimer: "billed in USD; your bank's exchange rate applies."

---

## What shipped in session 7 (this one)

The full Setup Intent verification flow shipped, hardened against arbitrage (billing **and** card-issuer country must match), and end-to-end verified on dev with real Stripe test infrastructure (3DS challenges and all). All 4 cells of the test matrix passed.

### Slim (`lg-stripe-billing`)
- **Migration 005**: `products.region_tag` column; old `low_income` test data cleaned
- **Catalog**: 4 new products (LITE/PRO × regional_a/regional_b) + 8 prices, `db_ref`/`region_tag` support in importer
- **StripeGateway**: `retrieveSetupIntent`, `retrievePaymentMethod`, `createSubscription`, `detachPaymentMethod`, `createCustomer`
- **ProductRepository**: `regionTagForPrice`, `countryInRegion`, `standardPriceForTierAndInterval`; `listMembership` rewired to product-level region_tag (regional wins over standard for same ref); `resolvePriceForCountry` reduced to pass-through
- **AdminActionLogRepository** (+ Pdo impl): audits every verification attempt to `admin_action_log` with billing + issuer countries
- **CheckoutService.createRegionalSetupSession()**: setup-mode session with metadata. Pre-creates Stripe Customer (setup mode does NOT auto-create from `customer_email` — verified via Stripe docs)
- **ReturnHandler.handleRegionalVerify()**: dual eligibility check (`countryInRegion` for both billing AND `pm.card.country` issuer); pass → create subscription with saved PM; fail → detach PM + 302 redirect with diagnostic query params
- **CheckoutController**: regional prices → setup session; `redirect_url` in result → 302 on `/v1/return`
- **SettingsStore.getRegionalFailUrl()**: env-driven, falls back to `APP_HOME_URL`
- **`checkout-test.html`**: rebuilt as country-driven dynamic console; renders failure-landing state with `Billing address: X · Card issuer: Y · Required region: Z` from URL params

### WP plugin (`lg-patreon-stripe-poller`)
- **`[lg_join]` country detection**: tiered resolution — URL `?country=XX` override > Cloudflare `/cdn-cgi/trace` > `ipapi.co` fallback. Detected country forwarded to both `/v1/products` (drives which tier cards render) and `/v1/checkout` body
- Auto-detection works on dev (after Cloudflare orange-cloud was enabled) and prod regardless of CF config thanks to ipapi fallback

### Test matrix (all passed live)
| Card | Stripe billing form | Result |
|---|---|---|
| `4000 0035 6000 0008` (IN) | India | ✅ PASS — sub created, $40 charged |
| `4242 4242 4242 4242` (US) | India | ✅ FAIL (issuer mismatch) — PM detached |
| `4000 0035 6000 0008` (IN) | United States | ✅ FAIL (billing mismatch) — PM detached |
| `4242 4242 4242 4242` (US) | United States | ✅ FAIL (both mismatch) — PM detached |

All confirmed against `admin_action_log` rows, Stripe Dashboard charge/PM/subscription state, and the failure-landing UI rendering correct diagnostic text.

### Cloudflare config change
- `dev.loothgroup.com` switched from "DNS only" (grey) to "Proxied" (orange) on Cloudflare. Means `CF-IPCountry` header now reaches Slim, `/cdn-cgi/trace` works, and we get all the WAF/rate-limit benefits for free. Apply same change to prod when cutting over (already in PROD-CUTOVER notes).

### Dev verification
- `/v1/products?country=US` → 2 standard products ✓
- `/v1/products?country=IN` → 2 regional_b products ($3/$20 LITE, $6/$40 PRO) ✓
- `/v1/products?country=MX` → 2 regional_a products ($4/$30 LITE, $8/$65 PRO) — auto-detected via real VPN exit ✓
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

### 2. ~~Expiry sweep cron (WP plugin)~~ — DONE

Already implemented in `EntitlementRepo::sweepExpiredGiftEntitlements()` and wired into `Tick::run()` "Pass 1.5". Verified end-to-end on dev: back-dated entitlement → ran cron tick → entitlement revoked, sync swept WP role removal. Log entry: `expiry sweep: revoked gift entitlements for customer_ids=...`.

### 3. `[lg_redeem_gift]` shortcode (WP plugin)

Member-facing gift redemption form. Renders code + email inputs, POSTs to `/billing/v1/redeem`, shows confirmation.

### 4. `charge.refunded` confirmation

Register event in Stripe webhook + confirm the handler revokes immediately. Currently unverified.

### 5. Production cutover

No legacy plugin to migrate — clean greenfield deploy to prod.

## System map

Open `docs/system-map.html` in a browser for the full architecture diagram.
