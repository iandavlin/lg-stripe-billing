# Pickup — lg-stripe-billing

*Last worked: 2026-04-29 (session 5)*

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
