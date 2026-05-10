# Pickup — lg-stripe-billing

*Updated: 2026-05-09 (session 16)*

## State

Slim 4 PHP API mounted at `https://dev.loothgroup.com/billing` (origin nginx serves `/home/ccdev/lg-stripe-billing/public/` via PHP-FPM pool `php8.3-fpm-lg-billing-dev.sock`). Companion WP plugin at `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/`. MySQL DB `lg_membership` (separate from WP's DB; user `lg_membership`, password in `.env`).

## TODO

- **NEXT:** Register `charge.refunded` in Stripe Dashboard webhook (handler is in code at `WebhookController`, not yet in the registered events list).
- Link affiliate `dan` to his WP user (`affiliates.wp_user_id`).
- Seed `affiliate-earnings` WP page.
- 409 `has_active_sub` CTA on `[lg_join]`.
- Server-enforce `quantity >= 2` on gift checkout in `/v1/checkout`.
- Production cutover (see `PROD-CUTOVER.md`).
- **Carryover from companion repo:** server has uncommitted membership-guide WIP that's the canonical version (not what's in `origin/main` after the session-16 revert). Commit it from the server when ready — don't pull origin over it.

## Two-repo system

| Repo | Lives | Role |
|---|---|---|
| `lg-stripe-billing` (this) | EC2: `/home/ccdev/lg-stripe-billing/` | Slim user-facing API |
| `lg-patreon-stripe-poller` | EC2: `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/` | WP plugin: pollers + arbiter + capabilities writer + shortcodes |

Bridge: Slim's `WpSync::trigger($customerId)` POSTs to `/wp-json/lg-member-sync/v1/sync-customer` after checkout. WP plugin's `Tick::run` Pass 1.7 POSTs back to Slim's `/v1/reconcile-pending` every cron. Both use `CURLOPT_RESOLVE` to pin the hostname to `127.0.0.1` — Cloudflare challenges PHP-curl from the origin and breaks server-to-server calls otherwise.

## Live endpoints

Slim (prefix `/billing`):

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/health` | — | Liveness probe |
| GET | `/v1/config` | — | Returns publishable key |
| GET | `/v1/products` | — | Active membership products + prices (`?country=XX` for regional) |
| POST | `/v1/checkout` | — | Create Stripe Checkout session (sub / gift / one-time / regional setup) |
| POST | `/v1/portal` | — | Create Stripe customer portal session |
| GET | `/v1/return` | — | Stripe redirect handler after checkout |
| POST | `/v1/redeem` | — | Redeem a gift code → grant entitlement + WP sync |
| POST | `/v1/webhook` | Stripe sig | Stripe webhook receiver |
| POST | `/v1/affiliate-click` | — | Record affiliate click (slug in body) |
| GET / POST | `/v1/affiliates` | X-LGMS-Token | Admin: list / create affiliates |
| GET | `/v1/affiliates/{id}/conversions` | X-LGMS-Token | Admin: list conversions for one affiliate |
| POST | `/v1/reconcile-pending` | X-LGMS-Token | Sweep unresolved sessions |

Webhook config (dev):
- ID: `we_1TR8nSHg6gcIV22bUqxeVvff`
- URL: `https://dev.loothgroup.com/billing/v1/webhook`
- Events: `product.created`, `product.updated`, `price.created`, `price.updated`, `customer.subscription.updated`, `customer.subscription.deleted`, `checkout.session.completed`
- Secret: `STRIPE_WEBHOOK_SECRET` in `.env`
- **TODO:** add `charge.refunded`

WP REST endpoints — see companion repo's PICKUP.md.

## Decisions locked in

**Subscription status policy:**
| Stripe status | Access |
|---|---|
| `active` | Full access |
| `trialing` | Full access |
| `past_due` | Keep through Stripe retry window |
| `canceled` | Revoke immediately |
| `refunded` | Revoke immediately |

**Affiliate commission model:** per-affiliate `commission_pct` (monthly), `commission_pct_annual` (annual sign-up), `retention_bonus_pct` (year-1 invoice total × %). Refunds via `charge.refunded` → `affiliate_debits` (`INSERT IGNORE` on `stripe_charge_id`). Payouts manual via "Request Withdrawal" → email to admin. Affiliate↔WP user via `affiliates.wp_user_id` (nullable, unique).

**Regional pricing:** 3 tiers (standard NULL, regional_a, regional_b), each a separate Stripe product. Setup-Intent flow verifies billing + issuer country before creating subscription. Gifts always use standard products.

**Gift / bulk:** `POST /v1/checkout` with `quantity >= 2` → one-time payment session. Price computed server-side from `BULK_DISCOUNT_TIERS`. N codes generated on return; emailed via WP plugin.

## .env vars (dev)

```
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
APP_BASE_URL=https://dev.loothgroup.com/billing
APP_BASE_PATH=billing
APP_HOME_URL=https://dev.loothgroup.com
APP_RETURN_SUCCESS_URL=https://dev.loothgroup.com/activity/
APP_REGIONAL_FAIL_URL=https://dev.loothgroup.com/regional-pricing-not-available/
LGMS_SYNC_URL=https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer
LGMS_GIFT_MAIL_URL=https://dev.loothgroup.com/wp-json/lg-member-sync/v1/send-gift-codes
LGMS_SHARED_SECRET=...
BULK_DISCOUNT_TIERS=10:10,20:20,50:30
DB_HOST=127.0.0.1
DB_NAME=lg_membership
DB_USER=lg_membership
DB_PASSWORD=...
```

## Quick test commands

```bash
# Health
curl -s https://dev.loothgroup.com/billing/health

# Products by country
curl -s 'https://dev.loothgroup.com/billing/v1/products?country=US'

# Affiliate click
curl -s -X POST https://dev.loothgroup.com/billing/v1/affiliate-click \
  -H 'Content-Type: application/json' -d '{"slug":"dan"}'

# Retention poller (dry run)
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77 \
  'php /home/ccdev/lg-stripe-billing/bin/poll-retention.php --dry-run'

# Sync one customer (fires WP plugin's Sync::customer for that ID)
SECRET=$(grep '^LGMS_SHARED_SECRET=' /home/ccdev/lg-stripe-billing/.env | cut -d= -f2)
curl -s -X POST -H "Content-Type: application/json" -H "X-LGMS-Token: $SECRET" \
  -d '{"customer_id":4}' \
  https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer

# Trigger WP plugin tick manually (also exercises the new GET_LOCK)
cd /var/www/dev && wp cron event run lgms_poll_tick
```

## Key gotchas

- **CF resolve pin (`CURLOPT_RESOLVE => host:port:127.0.0.1`)** is required for any server-to-server HTTP from PHP. `wp_remote_post()` doesn't honor this — use raw curl. Pattern is in both `WpSync::trigger` and `Tick::run` Pass 1.7.
- **Webhook errors are intentionally swallowed** in `WebhookController::handleCheckoutCompleted` — endpoint always returns 200 to Stripe so it doesn't retry for ~3 days; the WP plugin's polling sweep is the safety net (now hardened with GET_LOCK).
- **`redirect_url` in `ReturnHandler` is settings-only** — only ever derived from `.env` URLs + server-validated query params. No request-borne field reaches it. Don't add a path that takes user input here without validation.
- **DB credentials are in `.env`, not WP options.** wp-cli runs as the WP DB user and **cannot** query `lg_membership`. Use the env-file pattern in test commands above.

## Recent history

- **Session 16 (2026-05-09)** — security audit pass. Code change: `WpSync` http/https scheme allowlist (commit `542ab50`). Shipped four hardenings to companion repo (rate limit, GET_LOCK, dup-event detection, refund-window cap).
- **Session 15 (2026-05-07)** — affiliate system end-to-end. Migrations `013_affiliates.sql` through `017_affiliate_portal.sql`. Front-end portal, retention poller, refund debits via `charge.refunded`. See `git log --grep affiliate`.
- **Sessions 11–14** — orphan-charge recovery (`pending_sessions` table, `/v1/reconcile-pending`), Stripe Acacia → Basil migration, looth1 starter tier + welcome modal, unified pay-modal styling. See `git log` for detail.

## System map

`docs/system-map.html` — full architecture diagram. Open in browser.
