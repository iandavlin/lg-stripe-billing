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

## WordPress Plugin Settings (Settings → LG Member Sync)

- [ ] DB connection: host, name, user, password (`lg_membership_prod`)
- [ ] Stripe secret key (live)
- [ ] Shared secret (must match `LGMS_SHARED_SECRET` in `.env`)

## FluentCRM

- [ ] Create "Gift Purchasers" list: `wp eval 'use FluentCrm\App\Models\Lists; echo Lists::create(["title" => "Gift Purchasers", "slug" => "gift-purchasers"])->id . PHP_EOL;'`
- [ ] Set the list ID as a WP option: `wp option set lgms_gift_purchaser_list_id <ID>`

## FluentSMTP / Email

- [ ] Install FluentSMTP plugin
- [ ] Configure AWS SES connection (use IAM role on EC2 — no static keys needed)
- [ ] Send a test email to verify delivery
- [ ] Verify gift code email arrives after a test checkout

## Final Verification

- [ ] Run a manual subscription checkout end-to-end
- [ ] Run a manual gift checkout end-to-end, confirm email received + contact in FluentCRM
- [ ] Trigger WP cron manually: `wp cron event run lgms_poll_tick`
- [ ] Verify arbiter assigns correct role after subscription checkout
