# Pickup — lg-stripe-billing

*Last worked: 2026-05-01 (session 6)*

## NEXT — Setup Intent verification flow for regional-tier subscriptions

The big build for next session. We landed on the design but did not implement.

**Goal:** prevent VPN/IP arbitrage of regional pricing without eating Stripe's non-refundable fees on rejected fraud. Use Stripe Setup Intents to verify billing-country eligibility BEFORE charging.

**Flow:**
- Standard-tier subscriptions keep the current direct-subscribe Checkout flow (no change)
- Regional-tier subscriptions (price has `region_tag`) use Checkout in `mode: 'setup'` first:
  1. Customer enters card on Stripe; we get the saved payment method back, no charge made yet
  2. `/v1/return` retrieves the payment method, reads billing country, looks up `price_regions` for that `region_tag`
  3. **Pass:** create subscription via Stripe API using the saved PM. First charge happens. Customer sees normal "you're in" page.
  4. **Fail:** archive the payment method (no charge ever happened), redirect to a failure page that lets the customer either (a) subscribe at standard pricing or (b) contact support.
- Every verification attempt logged to `admin_action_log` (success and failure) for audit.

**Schema change:** move `region_tag` to the `products` table (it currently lives on `prices`). Three-tier model: `null` = standard, `regional_a` = mid-income, `regional_b` = deeper. Six Stripe products total (LITE/PRO × 3 tiers). Migrate the price-level seed.

**Pricing locked in (USD, no Adaptive Pricing FX gymnastics — we eat the lower revenue):**

| | Standard | Regional A | Regional B |
|---|---|---|---|
| LITE monthly | $5 | $4 | $3 |
| LITE yearly | $60 | $30 | $20 |
| PRO monthly | $11 | $8 | $6 |
| PRO yearly | $132 | $65 | $40 |

Country bucketing in `price_regions` per starter list in `PROD-CUTOVER.md` "Regional pricing" section.

**Gifts always use standard products** — no regional pricing on gift purchases (avoids arbitrage where a low-region buyer resells codes to high-region recipients).

**Cleanup needed:** dev DB has a leftover seed price `price_1TS4rpHg6gcIV22bJvmKYopL` ($2/mo LITE) tagged `region_tag='low_income'` plus a `price_regions` row mapping `IN → low_income`. Remove these as part of migrating to the new product-level schema.

**Files to touch (rough):**
- `db/migrations/005_products_region_tag.sql` — add `region_tag` column to `products`
- `src/Adapters/PdoProductRepository.php` + `src/Domain/Repositories/ProductRepository.php` — region filtering at the product level
- `src/Http/Controllers/CheckoutController.php` — branch on `region_tag` to use setup-mode session
- `src/Core/ReturnHandler.php` — verify billing country, create subscription on pass / archive PM on fail
- `src/Adapters/LiveStripeGateway.php` — add helpers for Setup Intent retrieval + payment method lookup + subscription create from saved PM
- New WP page or shortcode for the failure landing
- `bin/stripe-import-catalog.php` — handle 6 products + product-level `region_tag`
- `db/catalog.json` — add the 4 regional products + 8 prices
- `PROD-CUTOVER.md` — schema notes + country buckets

Estimated 3–4 hours of focused work.

## What shipped in session 6 (this one)

Everything below committed and pushed to both repos. Dev mirrors prod-bound state.

**Slim (`lg-stripe-billing`):**
- Active-sub guard on redeem (returns 409 with portal URL)
- Customer block flag on `customers` table (migration 003) — `CheckoutController` + `RedeemController` refuse blocked customers
- `admin_action_log` table (migration 004) — audit trail for every admin/customer self-service action
- Stripe API version pinned to `2024-12-18.acacia` in `LiveStripeGateway`
- Country detection on `/v1/products` via `?country=XX` or `CF-IPCountry` header → filters by `region_tag` (price-level, will be replaced by product-level next session)
- PROD-CUTOVER.md significantly expanded

**WP plugin (`lg-patreon-stripe-poller`):**
- `[lg_refund_request]` shortcode + `/refund-request` REST endpoint + admin email with Stripe dashboard hyperlinks
- `[lg_member_nav]` shortcode auto-discovers membership pages and renders nav with current-page highlight
- `[lg_manage_subscription]` rebuilt as full self-service UI: cancel (immediate or period-end), switch plan (now or next renewal via Subscription Schedules)
- Customer self-service REST: `/me/cancel-subscription`, `/me/switch-plan`
- Admin REST: `/admin/cancel-subscription`, `/admin/block-customer`, `/admin/refund-gift-purchase`
- `UserProfile` membership section on WP user-edit pages: subs with cancel/refund buttons (auto-block toggle on refund), gift purchases with refund-and-void, block toggle, recent admin-action audit table
- Guardrails: 24h plan-switch cooldown, refuse switch on `past_due`, refuse 2nd schedule, auto-block-on-refund opt-in
- AdminAlerts mailer for failures
- Customer confirmation emails on self-cancel / self-switch (sendSelfActionEmail)
- Stripe API version pinned to match Slim
- Discount-code input on `[lg_join]` form (URL `?promo=` still works as fallback)
- Brand-palette CSS pulled from Elementor kit (sage green `#87986A`, amber `#ECB351`)
- `[lg_join]` upgraded: hero attributes (heading/subheading/bullets/popular/taglines), side-by-side tier cards with "Most popular" badge + `is-selected` state, progressive disclosure (pick plan → form panel slides in)
- Page templates: full-width on `/lgjoin/` + `/lggift-buy/`, no-sidebar on the others
- `[lg_refund_request]` shows eligible items (radio per item: subscription / gift purchase) with 30-day window note; settings include `lgms_refund_email`, `lgms_refund_window_days`, `lgms_plan_switch_cooldown_hours`

**Dev verification:** end-to-end smoke test runs via `wp eval-file /tmp/smoke-test.php`. All guardrail and self-service tests pass. India regional price seeded for testing (will be removed next session).

**Server access for next session:**
```
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
```

`ianhatesguitars@gmail.com` / WP user 1824 is whitelisted for non-admin testing in `mu-plugins/dev-admin-only-login.php`.

---

## State at end of session

Everything works on dev. Full gift checkout tested end-to-end: 20-seat purchase → 20 gift codes in DB. Subscription webhooks live and tested (cancel, upgrade/downgrade, INSERT path). Test console at `checkout-test.html` covers both flows.

```
Browser ─► /billing/checkout-test.html
              │
              ├─ quantity=1 ─► POST /v1/checkout (subscription mode)
              │                  └─► Stripe Checkout ─► /v1/return
              │                        └─► customer + subscription + entitlement + WP sync
              │
              └─ quantity≥2 ─► POST /v1/checkout (gift/payment mode)
                                 └─► Stripe Checkout ─► /v1/return
                                       └─► customer + N gift_codes + POST /wp-json/.../send-gift-codes → FluentCRM

POST /v1/redeem {code, email, name?}
  └─► validate code → grant entitlement (expires_at = now + duration_days) → WP sync

Stripe Dashboard ─► subscription.updated/deleted ─► POST /v1/webhook
                                                       └─► upsert subscription + grant/revoke entitlement + WP sync

Stripe Dashboard ─► product.*/price.* ─► POST /v1/webhook
                                           └─► upsert products + prices (name + active only; ref/kind preserved)
```

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
| GET | `/v1/products` | Active membership products + prices (for shortcode tier picker) |
| POST | `/v1/checkout` | Create Stripe Checkout session (quantity=1→subscription, ≥2→gift) |
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

## Decisions locked in

### Subscription status policy
| Stripe status | Access |
|---|---|
| `active` | Full access to tier |
| `trialing` | Full access to trialing tier |
| `past_due` | Keep access through Stripe retry window |
| `canceled` | Revoke immediately |
| `refunded` | Revoke immediately, all cases |

### Gift / bulk membership model
- `POST /v1/checkout` with `quantity >= 2` → one-time Stripe payment session
- Price computed server-side: base per-seat × qty × (1 - discount%) using `BULK_DISCOUNT_TIERS` env
- Stripe line item uses **`product_data: {name: "..."}`** — NOT linked to Stripe product (gifts are a distinct SKU from subscriptions) — **see TODO #1 below**
- N gift codes generated in `gift_codes` table on return; emailed to purchaser via `mail()`
- Each code redeemed independently via `POST /v1/redeem`; grants entitlement with `expires_at = now + duration_days`
- `duration_days` derived from price: `grants_duration_days` field if set, else 365 for yearly, 30 for monthly

### Upgrade / downgrade
- Near-instant via `customer.subscription.updated` webhook
- Downgrade takes effect at period end

### Expiry sweep
- Gift code entitlements have `expires_at` — must be swept by cron
- **Must ship before gifts go on sale** (see TODO #3)

## Products/prices convention

```sql
-- Add a new tier after creating product in Stripe Dashboard:
INSERT INTO products (stripe_product_id, kind, ref, name, active)
VALUES ('prod_xxx', 'membership', 'looth3', 'Looth PRO', 1);
-- Webhook keeps name + active in sync going forward; ref/kind never overwritten
```

## Outstanding issues / TODOs, in priority order

### 1. ~~Fix gift checkout Stripe line item~~ — DONE

~~Currently using `product: stripe_product_id` in `price_data` (links to Stripe product).~~ Now uses `product_data: {name: "Looth LITE — 20-Seat Gift Pack"}`. `findPriceData()` returns `product_name` instead of `stripe_product_id`. Gift purchases no longer appear as subscription product sales in Stripe reporting.

### 2. ~~Email delivery for gift codes~~ — DONE

`GiftCodeMailer` (PHP `mail()`) replaced by `WpGiftMailer`. On `/v1/return`, Slim POSTs `{to_email, to_name, codes}` to the WP plugin's new `/wp-json/lg-member-sync/v1/send-gift-codes` endpoint (same shared-secret auth as `/sync-customer`). The WP plugin creates/updates a FluentCRM contact tagged `gift-purchaser` and sends the email via `wp_mail()` — routed through whatever FluentCRM/FluentSMTP has configured.

**Requires on dev/prod:** add `LGMS_GIFT_MAIL_URL=https://{site}/wp-json/lg-member-sync/v1/send-gift-codes` to `.env`. Remove `MAIL_FROM` (no longer read).

### 3. Expiry sweep cron (WP plugin)

Gift code entitlements expire (`expires_at` set on grant). The WP plugin poller needs a sweep:
```sql
-- Find expired gift entitlements
SELECT DISTINCT customer_id FROM entitlements
WHERE source_type = 'gift_code'
  AND expires_at IS NOT NULL
  AND expires_at < NOW()
  AND revoked_at IS NULL;
```
Then `revokeBySource('gift_code', id)` and fire WP sync for each customer. Same sweep also covers one-time yearly memberships when those launch.

### 4. `[lg_redeem_gift]` shortcode (WP plugin)

Member-facing gift redemption form. Renders code + email inputs, POSTs to `/billing/v1/redeem`, shows confirmation. Lives in `lg-patreon-stripe-poller`.

### 5. `charge.refunded` confirmation

Register event in Stripe webhook + confirm the handler revokes immediately. Currently unverified.

### 6. Production cutover

No legacy plugin to migrate — clean greenfield deploy to prod.

1. Set up `/var/www/billing/lg-stripe-billing/` — **owned by `ubuntu`**
2. Clone Slim, run `composer install --no-dev`
3. Create `lg_membership_prod` MySQL DB + user
4. Apply `db/schema.sql` + `db/migrations/001_gift_codes.sql` + seed (region tags only)
5. nginx: add `/billing/` location to `loothgroup.com.conf`
6. New php-fpm pool `lg-billing-live` running as `ubuntu`
7. `.env` with **live** Stripe keys + `LGMS_SHARED_SECRET` + `STRIPE_WEBHOOK_SECRET` + `BULK_DISCOUNT_TIERS` + `MAIL_FROM`
8. Deploy `lg-patreon-stripe-poller` to `/var/www/html/wp-content/plugins/`
9. Register prod webhook in Stripe (all 6 events + `charge.refunded`)
10. Configure plugin settings page
11. Verify a manual test checkout end-to-end

### 7. Optimization (nice-to-have)

`Sync::all()` iterates every customer on every cron tick. Track dirty customers and only sync those.

## DB state on dev (test data)

| customer_id | email | wp_user | notes |
|---|---|---|---|
| 3 | browsertest@ | 1817 fart.mcfartingham | purchased 20-seat gift pack (20 codes in gift_codes) |
| 4 | fartbutt@ | 1818 fartbutt | active sub (sub_1TRUR6…); prior canceled sub in history |
| 5 | stinkbutt@ | 1819 stinkbutt | canceled sub; redeemed TESTCODE0001 (gift entitlement expires 2027-04-29) |

## .env vars (dev)

```
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
APP_BASE_URL=https://dev.loothgroup.com/billing
APP_BASE_PATH=billing
APP_HOME_URL=https://dev.loothgroup.com
LGMS_SYNC_URL=https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer
LGMS_GIFT_MAIL_URL=https://dev.loothgroup.com/wp-json/lg-member-sync/v1/send-gift-codes
LGMS_SHARED_SECRET=...
BULK_DISCOUNT_TIERS=10:10,20:20,50:30
DB_HOST=127.0.0.1
DB_NAME=lg_membership
DB_USER=lg_membership
DB_PASSWORD=...
```

## Server access

```bash
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
```

## Quick test commands

```bash
# Health
curl -s https://dev.loothgroup.com/billing/health

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

# Trigger WP plugin tick manually
cd /var/www/dev && wp cron event run lgms_poll_tick

# Sync one customer
SECRET=$(grep '^LGMS_SHARED_SECRET=' /home/ccdev/lg-stripe-billing/.env | cut -d= -f2)
curl -s -X POST -H "Content-Type: application/json" -H "X-LGMS-Token: $SECRET" \
  -d '{"customer_id":4}' \
  https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer
```

## System map

Open `docs/system-map.html` in a browser for the full architecture diagram.
