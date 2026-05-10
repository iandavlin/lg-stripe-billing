# Production Cutover Checklist

Things to do when deploying to prod that require manual steps outside of `git pull` + `composer install`.
Add to this list whenever a dev-only setup step is taken that has no code equivalent.

---

## Server & Deploy

- [ ] Create `/var/www/billing/lg-stripe-billing/` owned by `ubuntu`
- [ ] Clone repo, run `composer install --no-dev`
- [ ] Create `lg_membership_prod` MySQL DB + user
- [ ] Apply `db/schema.sql` + every `db/migrations/NNN_*.sql` in order (001 through 007 as of session 9, plus `009_prices_discount_scale` added session 15 — see Gift duration section below) + seed (region tags only — products auto-create via `bin/stripe-import-catalog.php`)
- [ ] Add nginx `/billing/` location to `loothgroup.com.conf`
- [ ] New php-fpm pool `lg-billing-live` running as `ubuntu`
- [ ] Deploy `lg-patreon-stripe-poller` to `/var/www/html/wp-content/plugins/`

## Stripe

- [ ] Switch to live Stripe keys (`sk_live_…`, `pk_live_…`) in `.env`
- [ ] Run `php bin/stripe-import-catalog.php db/catalog.json` — creates products + prices in Live (idempotent)
- [ ] Apply printed SQL stamps to the prod `lg_membership` DB (sets `ref`/`kind` on products and `grants_duration_days` on one-time prices)
- [ ] Register prod webhook in Stripe Dashboard (events: `product.created`, `product.updated`, `price.created`, `price.updated`, `customer.subscription.updated`, `customer.subscription.deleted`, `charge.refunded`)
- [ ] Set `STRIPE_WEBHOOK_SECRET` in `.env` to prod webhook secret
- [ ] Create Stripe Coupon `patreon_migration` (5% off, expires after migration window) and Promotion Code `PATREON5`
- [ ] Configure Customer Portal at https://dashboard.stripe.com/settings/billing/portal:
    - Enable "Customers can switch plans"
    - Add Looth LITE + Looth PRO products with all prices (monthly + yearly + one-time annual where allowed)
    - Pick proration policy ("Always invoice" recommended for clearest UX)
    - Enable "Customers can cancel subscriptions" (default on)
    - Enable "Update payment methods" + "Update billing information" (defaults on)

## Security cutover (do not skip)

- [ ] **DO NOT copy dev `.env` to prod** — generate prod values fresh. A leaked dev secret should not unlock prod.
- [ ] Generate fresh `LGMS_SHARED_SECRET` for prod: `openssl rand -hex 32` — copy to both Slim's `.env` and the WP plugin's `lgms_shared_secret` setting. Verify they match.
- [ ] Generate prod `STRIPE_WEBHOOK_SECRET` by registering a *new* webhook endpoint in Stripe Dashboard for the prod URL. Do not reuse the dev secret.
- [ ] `APP_DEBUG=false` (or unset) — phpdotenv keeps strings, the literal `false` is now correctly handled (see `App.php`, sessions 16+). Anything other than `true`/`1`/`yes`/`on` suppresses error details.
- [ ] `APP_ENV=prod`
- [ ] `wp-config.php`: `WP_DEBUG = false`, `WP_DEBUG_DISPLAY = false` (display is false on dev too — keep it that way).
- [ ] `.env` perms: `chmod 600 /var/www/billing/lg-stripe-billing/.env` and `chown ubuntu:ubuntu`. Verify `ls -la .env` shows `-rw-------`.
- [ ] Run `composer audit` on both repos — no Critical or High CVEs in the dependency tree.
- [ ] Smoke-test on prod: hit a deliberately-broken Slim URL, confirm response is generic (no stack trace, no file paths, no SQL).
- [ ] Smoke-test gift-auth rate limit: 21 calls from one IP should yield HTTP 429 on the 21st (per-IP cap is 20/hr); 6 wrong-password attempts on a real account should yield 429 on the 6th (per-email cap is 5/15min).
- [ ] Audit log spot-check: cancel a test subscription as admin, verify a row appears in the audit log table with admin user / action / target.
- [ ] Verify the WP plugin's tick is acquiring `GET_LOCK('lgms_tick_lock')` (concurrent /run-now should log "tick SKIPPED").

## Environment (.env)

- [ ] `STRIPE_SECRET_KEY` — live key
- [ ] `STRIPE_PUBLISHABLE_KEY` — live key
- [ ] `STRIPE_WEBHOOK_SECRET` — prod webhook secret (fresh, not dev's)
- [ ] `APP_ENV=prod`
- [ ] `APP_DEBUG=false` (or omit entirely)
- [ ] `APP_BASE_URL=https://loothgroup.com/billing`
- [ ] `APP_BASE_PATH=billing`
- [ ] `APP_HOME_URL=https://loothgroup.com`
- [ ] `LGMS_SYNC_URL=https://loothgroup.com/wp-json/lg-member-sync/v1/sync-customer`
- [ ] `LGMS_GIFT_MAIL_URL=https://loothgroup.com/wp-json/lg-member-sync/v1/send-gift-codes`
- [ ] `LGMS_SHARED_SECRET` — generate fresh secret, match in WP plugin settings
- [ ] `BULK_DISCOUNT_TIERS` — confirm tiers with Ian before go-live (dev currently `5:5,10:10,20:20,50:30`)
- [ ] `APP_REGIONAL_FAIL_URL` — URL of the WP page hosting `[lg_regional_fail]` (falls back to `APP_HOME_URL` if unset). Default plugin slug: `https://loothgroup.com/regional-pricing-not-available/`
- [ ] `APP_RETURN_SUCCESS_URL` — post-checkout landing page. Set to the BuddyBoss activity feed: `https://loothgroup.com/activity/`. The plugin's `Plugin::maybePrintWelcomeModal` overlays a celebratory modal there when `_lg_pending_welcome` user meta is set; dismiss button clears the meta. The legacy `[lg_subscription_success]` shortcode + `/welcome/` page are retained but no longer the default landing.
- [ ] `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` — prod DB

## BuddyBoss Public Content Allow List

**Plugin auto-populates this on activation** via `Pages::ensureBuddyBossAllowlist()` for every page flagged `public => true` in the `PAGES` registry. Manual edits below are now optional.

If you do ever edit the allowlist by hand at WP Admin → BuddyBoss → Settings → General → Public Content, **always run `wp cache flush` afterward** — the BB option is read through object cache and a stale read can keep updates from taking effect. (Burned ~45 minutes on this in session 8 before tracing it.)

**Important behavior gotcha:** the `bp-enable-private-network` toggle being set to `0` (off) is **not** sufficient — BuddyBoss still gates pages on the public-content allowlist regardless of the toggle in some Pro configurations. Always populate the allowlist for any anon-accessible page. The auto-seed handles this; this note is here for when you eventually wonder why a page redirects to `wp-login.php?bp-auth=1&action=bpnoaccess`.

If the registry needs a new page added, edit `Pages::PAGES`, push, then click "Re-create / sync membership pages" in the LG Member Sync settings page. The seeder will both insert the page and append its slug to the allowlist.

## WordPress Plugin Settings (Settings → LG Member Sync)

- [ ] DB connection: host, name, user, password (`lg_membership_prod`)
- [ ] Stripe secret key (live)
- [ ] Shared secret (must match `LGMS_SHARED_SECRET` in `.env`)
- [ ] **Refund email**: address that receives `[lg_refund_request]` submissions and admin-action failure alerts. Leave blank to use the WP admin email.
- [ ] **Refund window (days)**: customer-facing eligibility window shown on `/request-refund/`. Default 30.
- [ ] **Plan-switch cooldown (hours)**: minimum hours between customer-initiated plan changes. Default 24. Set to 0 to disable.

## FluentCRM

- [ ] Create "Gift Purchasers" list: `wp eval 'use FluentCrm\App\Models\Lists; echo Lists::create(["title" => "Gift Purchasers", "slug" => "gift-purchasers"])->id . PHP_EOL;'`
- [ ] Set the list ID as a WP option: `wp option set lgms_gift_purchaser_list_id <ID>`

## WP options for gift / redemption flow

- [ ] `wp option set lgms_redeem_url 'https://loothgroup.com/<redeem-page-slug>/'` — used by GiftMailer to build clickable code links in the gift email. Falls back to `home_url('/lggift/')` if unset, so set it explicitly to whatever final slug `[lg_redeem_gift]` lives at.

## WP membership pages — auto-seeded on plugin activation

**Nothing to do manually.** The plugin's `LGMS\Wp\Pages::ensureAll()` runs on activation and creates every shortcode-hosting page with the right `[lg_member_nav][shortcode]` content, the right slug, the right `_wp_page_template`, and adds public-facing slugs to the BuddyBoss public-content allowlist (see section above).

If you ever need to re-sync (after editing `src/Wp/Pages.php`'s `PAGES` registry, for example), use the "Re-create / sync membership pages" button at WP Admin → Settings → LG Member Sync. Idempotent — existing pages are left alone (admins may have customized layout); only missing ones are inserted.

The seeded pages are:

- `/lgjoin/` → `[lg_member_nav][lg_join]`
- `/lggift-buy/` → `[lg_member_nav][lg_gift]`
- `/lggift/` → `[lg_member_nav][lg_redeem_gift]`
- `/manage-subscription/` → `[lg_member_nav][lg_manage_subscription]` (intentionally **not** in the BuddyBoss public-content allowlist — shortcode shows "Please sign in" for anon visitors)
- `/request-refund/` → `[lg_member_nav][lg_refund_request]`
- `/regional-pricing-not-available/` → `[lg_member_nav][lg_regional_fail]` — set `APP_REGIONAL_FAIL_URL` to this URL
- `/welcome/` → `[lg_member_nav][lg_subscription_success]` — set `APP_RETURN_SUCCESS_URL` to this URL

The plugin auto-enqueues `assets/lg-shortcodes.css` on any page containing one of these shortcodes — the tag list is derived from `Pages::PAGES`, so adding a new shortcode + page entry is the only edit needed.

## Admin tools available on user profile pages (no extra setup)

Any WP admin viewing a user-edit page (`/wp-admin/user-edit.php?user_id=X`) gets a "Membership" section at the bottom with:
- Cancel / Cancel & Refund buttons per active subscription (with optional auto-block on refund)
- Refund & Void per gift purchase
- Block / Unblock from future subscriptions (with reason textarea)
- Recent admin actions audit log

Customer-facing self-service is on `/manage-subscription/`: change plan (now or at next renewal) + cancel (immediate or at period end). Both paths call Stripe directly; webhooks revoke roles automatically.

## FluentSMTP / Email

- [ ] Install FluentSMTP plugin
- [ ] Configure AWS SES connection (use IAM role on EC2 — no static keys needed)
- [ ] Send a test email to verify delivery
- [ ] Verify gift code email arrives after a test checkout

## Cloudflare / Edge

- [ ] Configure Cloudflare rate limiting rule on `loothgroup.com/billing/v1/redeem` (e.g. 10 req / min / IP, block above) — protects the gift code redemption endpoint from brute force. Cloudflare Pro plan covers this.
- [ ] Optionally: Cloudflare WAF managed rules + bot fight mode on the entire `/billing/*` path.
- [ ] Confirm Cloudflare is forwarding `CF-IPCountry` to the origin (default behavior on every CF zone) — this is what `/v1/products` uses for regional pricing detection.

## Regional pricing (developing-world discount)

### Schema — product-level region_tag (session 7+)

Region tagging lives on `products.region_tag`, not `prices.region_tag`. This enables the Setup Intent verification flow: when a customer selects a regional price, they enter their card in Stripe setup mode (no charge), then we check the billing country before creating any subscription.

Three-tier model:

| `products.region_tag` | Who sees it | Checkout flow |
|---|---|---|
| `NULL` | Everyone (standard) | Direct subscription checkout |
| `regional_a` | Countries in `price_regions` mapped to `regional_a` | Setup Intent → verify billing country → subscribe |
| `regional_b` | Countries in `price_regions` mapped to `regional_b` | Setup Intent → verify billing country → subscribe |

Six products total: LITE Standard, LITE Regional A, LITE Regional B, PRO Standard, PRO Regional A, PRO Regional B. Regional products all use the same DB `ref` as their standard counterpart (`looth2`/`looth3`) so entitlements are granted identically.

### Pricing locked in (USD, no Adaptive Pricing FX gymnastics)

| | Standard | Regional A | Regional B |
|---|---|---|---|
| LITE monthly | $5 | $4 | $3 |
| LITE yearly | $60 | $30 | $20 |
| PRO monthly | $11 | $8 | $6 |
| PRO yearly | $132 | $65 | $40 |

Gifts always use standard products — no regional pricing on gift purchases (avoids arbitrage where a low-region buyer resells codes to high-region recipients).

### Activating regional pricing on prod

1. **Run migration 005** to add `products.region_tag`:
   ```sql
   source db/migrations/005_products_region_tag.sql;
   ```

2. **Import the catalog** (creates 4 new Stripe products + 8 prices):
   ```bash
   php bin/stripe-import-catalog.php db/catalog.json
   ```

3. **Apply the printed SQL stamps** after the webhook has synced the new products/prices down. The stamps set `ref`, `kind`, and `region_tag` on the products rows. Example output:
   ```sql
   UPDATE products SET ref = 'looth2', kind = 'membership', region_tag = 'regional_a' WHERE stripe_product_id = 'prod_xxx';
   UPDATE products SET ref = 'looth2', kind = 'membership', region_tag = 'regional_b' WHERE stripe_product_id = 'prod_yyy';
   UPDATE products SET ref = 'looth3', kind = 'membership', region_tag = 'regional_a' WHERE stripe_product_id = 'prod_zzz';
   UPDATE products SET ref = 'looth3', kind = 'membership', region_tag = 'regional_b' WHERE stripe_product_id = 'prod_www';
   ```

4. **Populate `price_regions`** with the country → region_tag map:
   ```sql
   -- Regional B: lower-income countries (~$3/mo LITE)
   INSERT INTO price_regions (country_code, region_tag) VALUES
     ('IN', 'regional_b'), ('NG', 'regional_b'), ('PH', 'regional_b'),
     ('ID', 'regional_b'), ('PK', 'regional_b'), ('BD', 'regional_b'),
     ('VN', 'regional_b'), ('EG', 'regional_b'), ('KE', 'regional_b'),
     ('GH', 'regional_b'), ('ET', 'regional_b'), ('TZ', 'regional_b'),
     ('UG', 'regional_b'), ('MM', 'regional_b'), ('KH', 'regional_b');

   -- Regional A: mid-income countries (~$4/mo LITE)
   INSERT INTO price_regions (country_code, region_tag) VALUES
     ('BR', 'regional_a'), ('MX', 'regional_a'), ('TR', 'regional_a'),
     ('AR', 'regional_a'), ('CO', 'regional_a'), ('PE', 'regional_a'),
     ('ZA', 'regional_a'), ('UA', 'regional_a'), ('PL', 'regional_a'),
     ('RO', 'regional_a'), ('TH', 'regional_a'), ('MY', 'regional_a'),
     ('CL', 'regional_a'), ('MA', 'regional_a'), ('JO', 'regional_a');
   ```

5. **Add `APP_REGIONAL_FAIL_URL`** to `.env` pointing to the WP page hosting `[lg_regional_fail]`:
   ```
   APP_REGIONAL_FAIL_URL=https://loothgroup.com/membership-not-available/
   ```
   If unset, the redirect falls back to `APP_HOME_URL` (safe, not pretty).

6. **Create the `[lg_regional_fail]` WP page** (see WP plugin TODOs). The page receives query params:
   - `reason=region_mismatch`
   - `region_tag=regional_a` (or `regional_b`)
   - `billing_country=XX`
   - `standard_price_id=price_xxx` (can be used to pre-fill a standard checkout link)

7. **Verify**: `curl 'https://loothgroup.com/billing/v1/products?country=IN'` returns Regional B prices; `?country=US` returns standard prices; `?country=BR` returns Regional A prices.

### Verification flow (how it works at runtime)

```
Customer picks Regional price from [lg_join]
  │
  ├─ POST /v1/checkout {price_id: "price_reg_xxx", email: ...}
  │     CheckoutController detects product_region_tag != null
  │     → CheckoutService.createRegionalSetupSession()
  │     → Stripe Checkout mode=setup (no charge)
  │
  ├─ Customer enters card → Stripe saves Setup Intent
  │
  ├─ GET /v1/return?session_id=cs_setup_xxx
  │     ReturnHandler.handleRegionalVerify()
  │       ├─ Expand setup_intent.payment_method
  │       ├─ Read billing_details.address.country
  │       ├─ countryInRegion(country, region_tag) ?
  │       │
  │       ├─ PASS: createSubscription(customer, price, pm_id)
  │       │         upsert subscription + grant entitlement + WP sync
  │       │         → JSON {ok:true} (normal success render)
  │       │
  │       └─ FAIL: detachPaymentMethod(pm_id)
  │                 log to admin_action_log (action=regional_verify, success=0)
  │                 → 302 redirect to APP_REGIONAL_FAIL_URL?reason=region_mismatch&...
  │
  └─ admin_action_log row written for every attempt (pass + fail)
```

## Gift recipient emails (Tier 1 gift management)

The plugin's `GiftMailer` orchestrates two email types for every gift purchase:
1. **Per-recipient HTML emails** — sent to each gift recipient when the buyer used the "Send each recipient directly" mode on `[lg_gift]`. Template at `templates/email/gift-recipient.html.php` (sage header, optional pull-quote message, big code, amber CTA, perks list). Mirrors `lg-stripe-billing/public/mockup-gift-email.html` — keep them in sync if the design evolves.
2. **Buyer summary** — always sent. Lists buyer-kept codes as clickable chips and addressed codes as a recipient/code table so the buyer has a record of who got what.

From-name on per-recipient emails reads "{Giver name} via The Looth Group" with `Reply-To: {giver_email}` so a recipient's "thanks!" lands in the buyer's inbox. Falls back to `lgms_refund_email` (or admin email) when the buyer's email is invalid.

Verify after cutover: a 2-code purchase in direct mode with two real test mailboxes should produce two recipient emails + one buyer summary, each rendering correctly across Gmail web, Apple Mail, and Outlook web (test those three minimum).

## Final Verification

- [ ] Run a manual subscription checkout end-to-end
- [ ] Run a manual gift checkout end-to-end, confirm email received + contact in FluentCRM
- [ ] Trigger WP cron manually: `wp cron event run lgms_poll_tick`
- [ ] Verify arbiter assigns correct role after subscription checkout
- [ ] Submit a refund request via `/request-refund/`, confirm HTML email lands at the configured destination
- [ ] As an active subscriber, attempt a gift redemption — confirm 409 with portal link
- [ ] As a customer, switch plans on `/manage-subscription/` (both "now" and "at renewal" timings)
- [ ] As an admin, run the guardrail test once on dev to catch any prod-config drift: `wp eval-file /tmp/guardrail-test.php`

## Pending-session reconciliation (orphan recovery)

Background: Stripe Embedded Checkout's parent-page redirect to /v1/return is fragile — modal close, browser crash, network drop, or phone sleep between Pay click and redirect leaves the customer charged on Stripe with no entitlement on our side.

The fix is server-side reconciliation: `pending_sessions` table records every Stripe Checkout session we create; `/v1/reconcile-pending` endpoint sweeps unresolved rows older than 60 seconds, polls Stripe, and runs ReturnHandler::handle() server-side to provision. WP plugin Tick::run() calls this endpoint every cron tick (now 5 min instead of hourly).

### Cutover steps

1. **Run migration 008**: `mysql ... < db/migrations/008_pending_sessions.sql` on prod DB. Creates the `pending_sessions` table.
2. **Verify the LGMS_SHARED_SECRET env var matches** between Slim's `.env` and WP's `lgms_shared_secret` option. The reconcile endpoint authenticates via X-LGMS-Token header.
3. **WP plugin re-schedules the cron event automatically** on first request after deploy via `Plugin::maybeRescheduleCron`. Verify with:
   ```
   wp eval 'echo wp_get_schedule(lgms_poll_tick);' --path=/var/www
   # expected: lgms_5min
   ```
4. **OS cron must be hitting wp-cron.php at >5 min cadence** for the reconcile sweep to actually fire. Check: `crontab -l | grep wp-cron`. If only running hourly, add a 5-minute job:
   ```
   */5 * * * * cd /var/www && /usr/bin/php wp-cron.php > /dev/null 2>&1
   ```
5. **No Stripe Dashboard config change is required** — we are not subscribing to checkout.session.completed at this time. The pending_sessions sweep is sufficient.
6. **Optional smoke test**: complete a checkout, kill the browser before the redirect, wait 5 minutes, verify entitlement appears in DB.

## looth1 role rework (starter tier, not lapsed)

In dev as of 2026-05-04, looth1 is repurposed from lapsed paid member to starter tier signed up but not paid. This requires:

1. **Deactivate code-snippets snippet #90** (Log Out Looth 1 Users Immediately) on prod. It force-logs-out any looth1 user on login. Use:
   ```
   wp db query 'UPDATE wp_snippets SET active=0 WHERE id=90' --path=/var/www
   ```
   Snippets #88 and #89 (also looth1 lockouts) are already inactive on dev; verify same on prod.
2. **Configure looth1 BuddyBoss permissions** in BB role settings: gate premium content, allow forum read access, basic site browsing. The plugin no longer demotes looth1 to customer on gift-auth login (RestController.php giftAuth() change).
3. **Audit any FluentCRM segments / automations / we miss you emails** that target looth1. They will now hit never-paid signups too. Re-target as needed (e.g. WHERE `role = looth1` AND `registered_via_lapse_path`).

## Welcome modal

WP plugin now shows a one-time celebratory modal in wp_footer when a user is upgraded into a paid tier (looth2+). Triggered by Arbiter setting `_lg_pending_welcome` user meta on the upgrade transition. Dismiss endpoint at `/lg-member-sync/v1/dismiss-welcome` (REST nonce auth).

No cutover steps — the modal CSS/JS is inline in Plugin::maybePrintWelcomeModal, no external assets to deploy.

## Webhook fast-path for orphan recovery

The polling sweep above keeps worst-case recovery at ~5-6 minutes. To drop that to ~5-10 seconds, subscribe Stripe to push completed-checkout events:

1. **Stripe Dashboard → Developers → Webhooks → edit the existing endpoint** at `/billing/v1/webhook`.
2. **Add event** `checkout.session.completed` to the subscribed list.
3. **Save.** No code change needed on prod — `WebhookController::handleCheckoutCompleted` is already wired.
4. **Smoke test:** complete a checkout, kill the browser before the redirect, watch the entitlement appear within seconds (instead of waiting for the next polling tick).

The webhook handler routes to the same idempotent `ReturnHandler::handle()` the polling sweep uses, so the two layers can both run on the same session safely. Polling stays as the safety net for any webhook deliveries Stripe drops.

## Welcome email

`LGMS\Wp\WelcomeMailer::sendIfNeeded` fires from `Arbiter::sync` on the looth1→paid-tier transition. Idempotency is guarded by the `_lg_welcome_email_sent_at` user meta — the email goes out exactly once per user even if Arbiter runs many times.

Template lives at `templates/email/welcome-membership.html.php` in the WP plugin. Edit the body there; no other config required.

If a customer needs the email re-sent (support recovery): `wp user meta delete <ID> _lg_welcome_email_sent_at` then trigger any sync (visit any page or run `wp cron event run lgms_poll_tick`).

## Gift duration pricing (1 / 3 / 6 month gifts)

`discount_scale` column added to `prices` table. Slim app reads `lgms_discount_scale` from Stripe price metadata and stores it; `CheckoutService` multiplies the global bulk discount by this factor per duration.

Migration to run on prod DB **before** deploying:
```sql
ALTER TABLE prices ADD COLUMN discount_scale DECIMAL(5,4) NOT NULL DEFAULT 1.0000;
```

After deploy, create gift-duration prices in the **Live** Stripe Dashboard for each tier:

| Tier | Duration | Price | `lgms_gift` | `lgms_discount_scale` | `grants_duration_days` |
|------|----------|-------|-------------|----------------------|----------------------|
| Looth LITE | 1 month  | $5   | `true` | `0.50` | `30`  |
| Looth LITE | 3 months | $15  | `true` | `0.70` | `90`  |
| Looth LITE | 6 months | $30  | `true` | `0.85` | `180` |
| Looth LITE | 1 year   | $66  | `true` | `1.00` | `365` |
| Looth PRO  | 1 month  | $11  | `true` | `0.50` | `30`  |
| Looth PRO  | 3 months | $33  | `true` | `0.70` | `90`  |
| Looth PRO  | 6 months | $66  | `true` | `0.85` | `180` |
| Looth PRO  | 1 year   | $145 | `true` | `1.00` | `365` |

Each price must be:
- **Type:** One-time (not recurring)
- **Metadata:** set `lgms_gift=true`, `lgms_discount_scale=<value>`, `grants_duration_days=<days>`
- **Product:** attached to the matching Looth LITE / Looth PRO product

The Stripe price sync handler (`ProductSyncHandler::handlePriceEvent`) will pick up `lgms_discount_scale` automatically. After creating the prices in Stripe, run:
```bash
php bin/stripe-import-catalog.php db/catalog.json
```
or wait for the next webhook `price.created` event to sync each price into the DB.

The WP gift shortcode (`[lg_gift]`) automatically detects all prices where `grants_duration_days IS NOT NULL` and renders a duration picker. No WP config change needed.

Existing one-time prices (the `$66` LITE and `$145` PRO annual pay-in-full) already have `grants_duration_days = 365` and will appear as the "1 year" option until replaced or supplemented.
