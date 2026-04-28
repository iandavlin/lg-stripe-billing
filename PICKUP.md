# Pickup — lg-stripe-billing

*Last worked: 2026-04-28*

## State at end of session

Everything works on dev. Webhook sync is live and tested end-to-end. Product/price changes in the Stripe Dashboard flow into the DB automatically.

```
Browser ─► dev.loothgroup.com/billing/v1/checkout ─► Stripe Checkout ─► /v1/return
                                                                          │
                                                                          ▼
                                              Slim writes customer / subscription / entitlement
                                              Slim POSTs /sync-customer to WP plugin
                                                                          │
                                                                          ▼
                                              WP plugin: provision user, write
                                              lg_role_sources(stripe, tier),
                                              run arbiter, write wp_capabilities

Stripe Dashboard ─► product.*/price.* events ─► POST /billing/v1/webhook
                                                          │
                                                          ▼
                                              DB upserts products + prices
                                              GET /v1/products always reflects live data
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
| POST | `/v1/checkout` | Create Stripe Checkout session |
| POST | `/v1/portal` | Create Stripe customer portal session |
| GET | `/v1/return` | Stripe redirect handler after checkout |
| POST | `/v1/webhook` | Stripe webhook receiver (product/price sync + subscription events) |

## Webhook endpoint (dev)

- Registered in Stripe: `we_1TR8nSHg6gcIV22bUqxeVvff`
- URL: `https://dev.loothgroup.com/billing/v1/webhook`
- Events: `product.created`, `product.updated`, `price.created`, `price.updated`
- Secret: in `.env` as `STRIPE_WEBHOOK_SECRET`
- **TODO:** Add `customer.subscription.updated`, `customer.subscription.deleted`, `charge.refunded` to the registered events

## Products/prices convention

Products and prices sync automatically from Stripe via webhooks. To add a new tier:
1. Create the product in Stripe Dashboard
2. Run one SQL to set `ref` and `kind` (metadata approach was dropped — too fragile):
   ```sql
   INSERT INTO products (stripe_product_id, kind, ref, name, active)
   VALUES ('prod_xxx', 'membership', 'looth3', 'Looth PRO', 1);
   ```
3. Trigger any `product.updated` event (edit description, etc.) — webhook syncs `name` and `active` automatically going forward

The `ProductSyncHandler` should be simplified to only sync `name` and `active` — not `ref` or `kind` (those are set once manually). **TODO:** make this change.

## Decisions locked in (2026-04-28)

### Subscription status policy
| Stripe status | Access |
|---|---|
| `active` | Full access to tier |
| `trialing` | Full access to trialing tier |
| `past_due` | Keep access through Stripe retry window |
| `canceled` | Revoke immediately |
| `refunded` | Revoke immediately, all cases (subscription and one-time) |

### Upgrade / downgrade
- Near-instant via `customer.subscription.updated` webhook handler (not yet built)
- Downgrade takes effect at period end (member keeps higher tier for remainder of paid period)

### One-time yearly memberships
- `grants_duration_days` field exists in schema
- Expiry enforcement **not yet built** — cron needs an expiry sweep:
  ```sql
  UPDATE entitlements SET active = 0
  WHERE expires_at IS NOT NULL AND expires_at < NOW() AND active = 1
  ```
  Then fire WP sync for each affected customer.
- **Must ship before one-time yearly goes on sale**

### Gift memberships
- Gift code system (not custom checkout field — too fragile on typos)
- Purchaser checks out, return handler generates a unique code, emails it to purchaser
- Recipient redeems at `[lg_redeem_gift]` shortcode
- New table needed: `gift_codes`
- New Slim endpoint: `POST /v1/redeem`

### Bulk memberships (shops, schools, factories)
- Handled as bulk-priced gift packs — no org seat management needed
- Shop buys a 10-pack product, gets 10 codes to distribute
- Each employee/student redeems independently, gets a standalone membership
- Return handler detects bulk product, generates N codes, emails all to purchaser
- Employees keep access even if shop doesn't renew (by design)

## Next steps, in priority order

### 1. `customer.subscription.updated` webhook handler (NEXT)

Add to Slim's `WebhookController` + register event in Stripe:
- On `subscription.updated`: update `subscriptions` table, re-resolve tier, fire `/sync-customer`
- Handles upgrade, downgrade, trial-end, renewal, past_due transitions
- Also register `customer.subscription.deleted` (maps to cancel cascade)

### 2. Simplify `ProductSyncHandler`

Strip `ref` and `kind` from the upsert — only sync `name` and `active`. Metadata approach was too error-prone (key naming convention broke in testing).

### 3. Gift code system

New migration: `gift_codes` table:
```sql
CREATE TABLE gift_codes (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code             CHAR(12)        NOT NULL,
    tier             VARCHAR(64)     NOT NULL,
    duration_days    INT UNSIGNED    NOT NULL,
    purchased_by     BIGINT UNSIGNED NOT NULL,  -- customers.id
    redeemed_by      BIGINT UNSIGNED NULL,
    stripe_session_id VARCHAR(128)   NULL,
    expires_at       DATETIME        NULL,
    redeemed_at      DATETIME        NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_code (code),
    CONSTRAINT fk_gc_purchased FOREIGN KEY (purchased_by) REFERENCES customers(id),
    CONSTRAINT fk_gc_redeemed  FOREIGN KEY (redeemed_by)  REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

New endpoint: `POST /v1/redeem` — validates code, grants entitlement, fires WP sync.

### 4. Cutover to production

Currently everything is dev. Live (loothgroup.com) still on legacy plugin. Migration steps:

1. Set up `/var/www/billing/lg-stripe-billing/` — **owned by `ubuntu`** per the flag below
2. Clone Slim, run `composer install`
3. Create production `lg_membership_prod` MySQL DB + user
4. Apply schema + seed (region tags only — no fake product rows)
5. Seed products/prices for prod (trigger via Stripe events after setting live keys)
6. nginx config: add `/billing/` location to `loothgroup.com.conf`
7. New php-fpm pool `lg-billing-live` running as `ubuntu`
8. `.env` with **live** Stripe keys + `LGMS_SHARED_SECRET` + `STRIPE_WEBHOOK_SECRET`
9. Deploy `lg-patreon-stripe-poller` to `/var/www/html/wp-content/plugins/`
10. Register prod webhook in Stripe (same events as dev)
11. Configure plugin's settings page with prod DB creds + Stripe key + shared secret
12. **Disable the legacy `lg-stripe-membership` plugin on prod**
13. Verify a manual test checkout, watch the cascade

### 5. Optimization (nice-to-have)

`Sync::all()` currently iterates every customer on every cron tick. Track "dirty" customers in pass 1 of `Tick::run` and only sync those in pass 2.

### 6. Refund / dispute handlers

`charge.refunded` is wired (needs confirming it revokes immediately per the decision above). `charge.dispute.created` is unhandled — admin manually deals with disputes for now.

## Production deploy ownership (flag)

Dev install lives under `/home/ccdev/lg-stripe-billing` (ccdev owned). **Production install must live under a path owned by `ubuntu`** — matches the rest of the prod ops surface. Likely target: `/var/www/billing/lg-stripe-billing` or `/home/ubuntu/lg-stripe-billing`. Pool config + systemd units will need the matching user at cutover.

## Server access

```bash
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
```

For ubuntu-owned operations (sudo, plugin folder moves), log in as ubuntu separately.

## Quick test commands

```bash
# Health
curl -s https://dev.loothgroup.com/billing/health

# Products (tier picker data)
curl -s https://dev.loothgroup.com/billing/v1/products

# Browser end-to-end
open https://dev.loothgroup.com/billing/checkout-test.html

# Trigger WP plugin tick manually
cd /var/www/dev && wp cron event run lgms_poll_tick

# Sync one customer through the plugin REST endpoint
SECRET=$(sudo grep '^LGMS_SHARED_SECRET=' /home/ccdev/lg-stripe-billing/.env | cut -d= -f2)
curl -s -X POST -H "Content-Type: application/json" -H "X-LGMS-Token: $SECRET" \
  -d '{"customer_id":3}' \
  https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer

# Inspect lg_membership state
mysql -h 127.0.0.1 -u lg_membership -p'<password>' lg_membership -e "SHOW TABLES;"
```

## DB state on dev (test data)

| customer_id | email | wp_user | tier | notes |
|---|---|---|---|---|
| 1, 2 | smoketest+1, +public | — | — | curl-only tests, no Stripe customer |
| 3 | browsertest@ | 1817 fart.mcfartingham | looth2 | created via legacy plugin |
| 4 | fartbutt@ | 1818 fartbutt | looth2 | full new pipeline |
| 5 | stinkbutt@ | 1819 stinkbutt | looth1 (defaulted) | used for cancel cascade test; ready for resubscribe test |

## System map

Open `docs/system-map.html` in a browser for the full architecture diagram, table-by-table breakdown, flow walkthroughs, and class inventory.
