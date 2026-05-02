# Production Cutover Checklist

Things to do when deploying to prod that require manual steps outside of `git pull` + `composer install`.
Add to this list whenever a dev-only setup step is taken that has no code equivalent.

---

## Server & Deploy

- [ ] Create `/var/www/billing/lg-stripe-billing/` owned by `ubuntu`
- [ ] Clone repo, run `composer install --no-dev`
- [ ] Create `lg_membership_prod` MySQL DB + user
- [ ] Apply `db/schema.sql` + `db/migrations/001_gift_codes.sql` + seed (region tags only)
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

## Environment (.env)

- [ ] `STRIPE_SECRET_KEY` — live key
- [ ] `STRIPE_PUBLISHABLE_KEY` — live key
- [ ] `STRIPE_WEBHOOK_SECRET` — prod webhook secret
- [ ] `APP_BASE_URL=https://loothgroup.com/billing`
- [ ] `APP_BASE_PATH=billing`
- [ ] `APP_HOME_URL=https://loothgroup.com`
- [ ] `LGMS_SYNC_URL=https://loothgroup.com/wp-json/lg-member-sync/v1/sync-customer`
- [ ] `LGMS_GIFT_MAIL_URL=https://loothgroup.com/wp-json/lg-member-sync/v1/send-gift-codes`
- [ ] `LGMS_SHARED_SECRET` — generate fresh secret, match in WP plugin settings
- [ ] `BULK_DISCOUNT_TIERS` — confirm tiers with Ian before go-live
- [ ] `APP_REGIONAL_FAIL_URL` — URL of the WP page hosting `[lg_regional_fail]` (falls back to `APP_HOME_URL` if unset)
- [ ] `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` — prod DB

## BuddyBoss Public Content Allow List

The front-end pages hosting our shortcodes must be added to the **public content** list at WP Admin → BuddyBoss → Settings → General → Public Content. Otherwise non-logged-in visitors get redirected to `wp-login.php?bp-auth=1&action=bpnoaccess`.

**Important:** the `bp-enable-private-network` toggle being set to `0` (off) is **not** sufficient — BuddyBoss still gates pages on the public-content allowlist regardless of the private-network toggle in some Pro configurations. Always populate the allowlist for any anon-accessible page. (Discovered the hard way during session 8 — long debug session ended with object-cache also being stale; flush after edits.)

Pages to add (use the final slugs you pick):

- [ ] `/join/` (or wherever `[lg_join]` lives)
- [ ] `/gift/` (or wherever `[lg_gift]` lives)
- [ ] `/redeem/` (or wherever `[lg_redeem_gift]` lives)
- [ ] `/request-refund/` (or wherever `[lg_refund_request]` lives) — let anonymous users submit refund requests for unexpected charges before they remember to log in
- [ ] `/regional-pricing-not-available/` (or wherever `[lg_regional_fail]` lives) — anon visitors will land here from the Slim 302 after a failed regional billing-country verification. Slug must match `APP_REGIONAL_FAIL_URL` in Slim `.env`.
- [ ] Stripe return path is matched by `session_id=` query string already — no entry needed
- ~~`/manage-subscription/`~~ — **do NOT whitelist**. The shortcode shows "Please sign in" to anonymous users, so making it public has no benefit. Keep it members-only.

After editing the allowlist via WP admin, run `wp cache flush` and `wp rewrite flush` — the BB option is read through object cache and a stale read can keep an updated allowlist from taking effect.

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

## WP membership pages — create with [lg_member_nav] prepended

The five membership pages should each start with `[lg_member_nav]` followed by the page-specific shortcode. Auto-discovered nav links between them; current page highlighted.

- [ ] `/lgjoin/` (or chosen slug) → `[lg_member_nav][lg_join]`
- [ ] `/lggift-buy/` (or chosen slug) → `[lg_member_nav][lg_gift]`
- [ ] `/lggift/` (or chosen slug) → `[lg_member_nav][lg_redeem_gift]`
- [ ] `/manage-subscription/` (or chosen slug) → `[lg_member_nav][lg_manage_subscription]`
- [ ] `/request-refund/` (or chosen slug) → `[lg_member_nav][lg_refund_request]`
- [ ] `/membership-not-available/` (or chosen slug) → `[lg_regional_fail]` — regional billing-country failure landing (set URL in `APP_REGIONAL_FAIL_URL`)

The plugin auto-enqueues a baseline stylesheet (`assets/lg-shortcodes.css`) on any page containing one of these shortcodes — handles success/error states and form polish. Theme CSS can override.

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

## Final Verification

- [ ] Run a manual subscription checkout end-to-end
- [ ] Run a manual gift checkout end-to-end, confirm email received + contact in FluentCRM
- [ ] Trigger WP cron manually: `wp cron event run lgms_poll_tick`
- [ ] Verify arbiter assigns correct role after subscription checkout
- [ ] Submit a refund request via `/request-refund/`, confirm HTML email lands at the configured destination
- [ ] As an active subscriber, attempt a gift redemption — confirm 409 with portal link
- [ ] As a customer, switch plans on `/manage-subscription/` (both "now" and "at renewal" timings)
- [ ] As an admin, run the guardrail test once on dev to catch any prod-config drift: `wp eval-file /tmp/guardrail-test.php`
