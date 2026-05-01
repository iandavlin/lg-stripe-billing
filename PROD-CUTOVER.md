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
- [ ] `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` — prod DB

## BuddyBoss Public Content Allow List

If the live site has BuddyBoss "Private Network" mode enabled, the front-end pages hosting our shortcodes must be added to the **public content** list at WP Admin → BuddyBoss → Settings → General → Public Content. Otherwise non-logged-in visitors get redirected to login before they can buy.

Pages to add (use the final slugs you pick):

- [ ] `/join/` (or wherever `[lg_join]` lives)
- [ ] `/gift/` (or wherever `[lg_gift]` lives)
- [ ] `/redeem/` (or wherever `[lg_redeem_gift]` lives)
- [ ] `/request-refund/` (or wherever `[lg_refund_request]` lives) — let anonymous users submit refund requests for unexpected charges before they remember to log in
- [ ] Stripe return path is matched by `session_id=` query string already — no entry needed
- ~~`/manage-subscription/`~~ — **do NOT whitelist**. The shortcode shows "Please sign in" to anonymous users, so making it public has no benefit. Keep it members-only.

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

Infrastructure is built into the catalog: `prices.region_tag` + `price_regions` table. To enable on prod:

1. **Create regional Stripe prices** for each tier (e.g. a $2/month "Looth LITE — low income" alongside the standard $5/month). Same Product, additional Price.
2. **Tag them in our DB** via `db/catalog.json` and re-run `php bin/stripe-import-catalog.php db/catalog.json`. Use a `region_tag` like `low_income` and a lower `priority` (e.g. `50`) than the default-region prices (`100`) so the regional price wins in the resolver.
3. **Populate `price_regions`** with the country → region_tag map. SQL example:
    ```sql
    INSERT INTO price_regions (country_code, region_tag) VALUES
      ('IN', 'low_income'), ('NG', 'low_income'), ('PH', 'low_income'),
      ('BR', 'low_income'), ('ID', 'low_income'), ('VN', 'low_income'),
      ('PK', 'low_income'), ('BD', 'low_income'), ('EG', 'low_income'),
      ('KE', 'low_income');  -- adjust per actual policy
    ```
4. **Verify**: `curl 'https://loothgroup.com/billing/v1/products?country=IN'` should return the low-income prices for visitors detected from those countries; default (`region_tag: null`) prices for everyone else. The `[lg_join]` shortcode shows a "Regional pricing applied for IN" note when a regional price was returned.

## Final Verification

- [ ] Run a manual subscription checkout end-to-end
- [ ] Run a manual gift checkout end-to-end, confirm email received + contact in FluentCRM
- [ ] Trigger WP cron manually: `wp cron event run lgms_poll_tick`
- [ ] Verify arbiter assigns correct role after subscription checkout
- [ ] Submit a refund request via `/request-refund/`, confirm HTML email lands at the configured destination
- [ ] As an active subscriber, attempt a gift redemption — confirm 409 with portal link
- [ ] As a customer, switch plans on `/manage-subscription/` (both "now" and "at renewal" timings)
- [ ] As an admin, run the guardrail test once on dev to catch any prod-config drift: `wp eval-file /tmp/guardrail-test.php`
