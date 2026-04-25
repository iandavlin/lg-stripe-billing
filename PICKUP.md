# Pickup — lg-stripe-billing

*Last worked: 2026-04-25 (long session, dev fully operational)*

## State at end of session

Everything works on dev. Browser-tested end-to-end, default cascade verified, multi-source arbitration verified.

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
```

Plus: hourly WP cron polls Stripe Events API and Patreon API, both feed `lg_role_sources`, arbiter merges. One arbiter, one writer of `wp_capabilities`.

## Two-repo system

| Repo | Lives | Role |
|---|---|---|
| [`lg-stripe-billing`](https://github.com/iandavlin/lg-stripe-billing) (this) | EC2: `/home/ccdev/lg-stripe-billing/` (dev) | Slim user-facing API |
| [`lg-patreon-stripe-poller`](https://github.com/iandavlin/lg-patreon-stripe-poller) | EC2: `/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/` | WP plugin: pollers + arbiter + capabilities writer |

Retired (folder renamed `*.deprecated-2026-04-25`):
- `lg-stripe-membership` (legacy plugin)
- `lg-member-sync` (intermediate plugin, folded into lg-patreon-stripe-poller)
- `lg-patreon-sync` (CSV-based legacy)

## Slim is feature-complete for current scope

- ✅ Schema + migrations
- ✅ Domain DTOs + repository interfaces
- ✅ Adapters (Pdo*, LiveStripeGateway, EnvSettingsStore)
- ✅ Core services (CheckoutService, CustomerManager, EntitlementManager, ReturnHandler)
- ✅ Routes (`/v1/checkout`, `/v1/portal`, `/v1/return`, `/v1/config`, `/health`)
- ✅ DI wired
- ✅ nginx + php-fpm pool live at `https://dev.loothgroup.com/billing/`
- ✅ ReturnHandler fires `/sync-customer` to WP plugin
- ✅ Idempotency on entitlement grant (no duplicate rows on refresh)

## Next steps, in priority order

### 1. Frontend join page (BIGGEST gap — no real users yet)

Replace `public/checkout-test.html` with a member-facing flow:

- Add a `[lg_join]` shortcode in the WP plugin (`lg-patreon-stripe-poller`) that renders tier picker + embedded Stripe Checkout
- Frontend JS calls Slim's `POST /v1/checkout`, gets clientSecret, mounts via Stripe.js
- Replace whatever's on the existing `/join/` page on dev.loothgroup.com
- Reference existing pattern: the legacy `lg-stripe-membership` plugin's `renderShortcode` method (still on disk at `/var/www/dev/wp-content/plugins/lg-stripe-membership.deprecated-2026-04-25/class-checkout.php`)

Estimated: ~150 lines, 1-2 hours.

### 2. Customer Portal entry point

`POST /v1/portal` works server-side but no UI:

- `[lg_manage_subscription]` shortcode, visible to logged-in `looth2`+ users
- Button posts to `/v1/portal` with the user's email, redirects to Stripe portal URL
- ~30 lines.

### 3. Resubscribe-after-default test

We tested cancel cascade. Haven't tested same-email coming back:

- Take customer 5 (currently lapsed at looth1 from today's default test)
- Run a new browser checkout with same email `stinkbutt@example.com`
- Verify: same `customer_id`, same `wp_user_id`, new subscription row, new entitlement, capabilities back to `looth2`
- ~5 minutes, no code changes expected.

### 4. Cutover to production

Currently everything is dev. Live (loothgroup.com) still on legacy plugin. Migration steps:

1. Set up `/var/www/billing/lg-stripe-billing/` (or `/home/ubuntu/lg-stripe-billing/`) — **owned by `ubuntu`** per the flag below
2. Clone Slim, run `composer install`
3. Create production `lg_membership_prod` MySQL DB + user
4. Apply schema
5. Seed products/prices/regions for prod (different Stripe price IDs)
6. nginx config: add `/billing/` location to `loothgroup.com.conf`, point at new php-fpm pool
7. New php-fpm pool `lg-billing-live` running as `ubuntu`
8. `.env` with **live** Stripe keys (sk_live_…, pk_live_…) + `LGMS_SHARED_SECRET`
9. Deploy `lg-patreon-stripe-poller` to `/var/www/html/wp-content/plugins/`
10. Configure plugin's settings page with prod DB creds + Stripe key + shared secret
11. **Disable the legacy `lg-stripe-membership` plugin on prod** (it's still serving live members today)
12. Verify a manual test checkout, watch the cascade
13. Stripe Dashboard: webhook URL on the prod side currently points at the legacy plugin — leave it (poller handles everything now), or remove the webhook entirely

### 5. Optimization (nice-to-have)

`Sync::all()` currently iterates every customer on every cron tick. Track "dirty" customers in pass 1 of `Tick::run` and only sync those in pass 2. Linear → constant for unchanged users.

### 6. Refund / dispute handlers

`charge.refunded` is wired (revokes via subscription lookup). `charge.dispute.created` is unhandled — admin manually deals with disputes for now.

## Production deploy ownership (flag)

Dev install lives under `/home/ccdev/lg-stripe-billing` (ccdev owned). **Production install must live under a path owned by `ubuntu`** — matches the rest of the prod ops surface and avoids privilege drift between systemd/php-fpm pools. Likely target: `/var/www/billing/lg-stripe-billing` or `/home/ubuntu/lg-stripe-billing`. Pool config + systemd units will need the matching user at cutover.

## Server access

```bash
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
```

For ubuntu-owned operations (sudo, plugin folder moves), log in as ubuntu separately.

## Quick test commands

```bash
# Health
curl -s https://dev.loothgroup.com/billing/health

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
mysql -e "SHOW TABLES;"   # uses ~/.my.cnf for lg_membership user
```

## System map (for the full picture)

Open `docs/system-map.html` in a browser for the full architecture diagram, table-by-table breakdown, flow walkthroughs, and class inventory.

## Customers / WP users currently on dev (test data)

| customer_id | email | wp_user | tier | notes |
|---|---|---|---|---|
| 1, 2 | smoketest+1, +public | — | — | curl-only tests, no Stripe customer |
| 3 | browsertest@ | 1817 fart.mcfartingham | looth2 | created via legacy plugin during today's testing |
| 4 | fartbutt@ | 1818 fartbutt | looth2 | full new pipeline |
| 5 | stinkbutt@ | 1819 stinkbutt | **looth1** (defaulted) | used for cancel cascade test |

Customer 5 is lapsed and ready for the resubscribe test.
