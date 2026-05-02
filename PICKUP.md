# Pickup — lg-stripe-billing

*Last worked: 2026-05-02 (session 10)*

## NEXT — pick Phase D first (recommended) or Phase C

Tier 2 Phase A+B shipped this session. Logged-in buyers see a stripped checkout, codes attach to their account, redirect lands at `/my-gifts/`, and the dashboard renders read-only. **Action buttons in the dashboard are non-functional stubs** (CSS class `lg-mygifts__action-soon`); the loop isn't closed yet.

**Recommended next: Phase D** — wire up Send / Resend / Reassign / Void on `[lg_my_gifts]`. New Slim endpoints (`/v1/gift-send`, `/v1/gift-reassign`, `/v1/gift-void`), each re-using the existing `WpGiftMailer` send pipeline. Plus a "send batch" UI for filling multiple unsent rows at once. After Phase D, members can fully manage gifts end-to-end.

**Then Phase C** — opens the dashboard to non-members. Logged-out buyers at qty ≥ 4 get a "Create login + manage from dashboard" send-mode option. On purchase, Slim's `/v1/return` calls a new WP plugin endpoint that does `wp_insert_user()` with the **`customer` role** (NOT looth1 — that's reserved for lapsed members) and a temp password, then sends a credentials-included variant of the dashboard email. The `dashboard_mode=1` Slim path is already there from session 10; only the WP-side user-creation + credentials email branch is new.

Then in priority order:
1. **Redemption notification** — when a recipient redeems, email the buyer ("Sarah just redeemed your gift!"). Fires from `GiftRedemptionService`.
2. **`charge.refunded` webhook** — register the event in Stripe + verify our handler revokes immediately. Currently unverified per session 6 notes.
3. **Production cutover** — clean greenfield deploy. See full checklist in PROD-CUTOVER.md.

## What shipped in session 10 (this one)

### Tier 2 Phase A+B — gift dashboard for logged-in buyers

The setup for the full self-service gift management. Logged-in buyers now get a stripped checkout, codes attached to their account, and a dashboard at `/my-gifts/` to manage them. Phase C+D (account creation + action buttons) extend this in subsequent sessions.

**WP plugin (`lg-patreon-stripe-poller`)**
- **`looth1` role + `manage_gift_codes` capability** registered on activation. Looth2/3 + administrators inherit the cap so members who buy gifts get dashboard access without needing the looth1 role.
- **`Pages.php` registry**: `/my-gifts/` added with `visibility=gift_buyers` (only shows in `[lg_member_nav]` when the user has at least one purchased gift code). New `currentUserHasGiftCodes()` helper with `_lgms_has_gifts` user-meta cache primed lazily on first read and stamped explicitly post-purchase.
- **`[lg_my_gifts]`** shortcode: 3-bucket dashboard (Unsent / Sent — awaiting redemption / Redeemed). Read-only for now; action buttons stubbed (`Send`, `Resend`, `Reassign`) for Phase D.
- **`[lg_gift]` logged-in branch**: detected at render time. Hides mode toggle, recipient repeater, and buyer-email field via `.lg-gift--logged-in` CSS. Adds a top banner: "Hi {name} — codes will land in your gift dashboard." JS sets `dashboard_mode=1` in the `/v1/checkout` body.
- **`gift-buyer-dashboard.html.php`** email template: short "you have N gift codes ready to send" message with big amber "Open your gift dashboard →" CTA. No code values inline (those live in the dashboard).
- **`GiftMailer::send()` accepts `$dashboardMode`** — short-circuits the per-recipient + bulk-summary paths, sends the dashboard email instead.
- **`RestController::sendGiftCodes`** accepts `dashboard_mode` flag, forwards it to GiftMailer, and stamps `Pages::markHasGifts()` on the buyer's WP user post-send so the nav item appears immediately.

**Slim (`lg-stripe-billing`)**
- **`CheckoutController` + `CheckoutService` + `ReturnHandler`** all accept and route the new `dashboard_mode` flag. When set:
  - Stored in Stripe session metadata as `dashboard_mode=1`.
  - On `/v1/return`, `handleGift` redirects to `{APP_HOME_URL}/my-gifts/` instead of `/welcome/`.
  - `WpGiftMailer` payload includes the flag so the WP plugin renders the dashboard email template.
- **`WpGiftMailer::sendGiftCodes()` signature** gains a fourth optional `bool $dashboardMode` parameter.

**Dev verification:**
- looth1 role registered (was already present from prior config; cap added cleanly).
- `/my-gifts/` page auto-created at id 69066, in BuddyBoss allowlist, in dev mu-plugin allowlist.
- Caches flushed (object cache + permalinks).

### Role correction (late in session)
- Initially used `looth1` as the auto-created role for new gift-only buyers. Wrong: `looth1` is reserved on this site for lapsed members (used by `Arbiter`, `sync-engine`, `UserProvisioner` as the cancel/fallback state).
- Fixed: `Plugin::GIFT_ROLE = 'customer'` (legacy WooCommerce role). The `manage_gift_codes` capability is granted to `customer`, all looth tiers (so lapsed members with legacy gifts still see the dashboard), and admin. Activation method renamed `registerGiftRole` → `registerGiftCapability` since we no longer mint a role.

### What's left after this session
- Phase D (next): wire the dashboard action buttons (Send / Resend / Reassign / Void) + matching Slim API endpoints. **Recommended next.**
- Phase C: customer-role user auto-creation for non-members at checkout (qty ≥ 4 "create login" mode). Hooks into existing dashboard_mode=1 path; only WP-side wp_insert_user + credentials email is new.

### Earlier session 9 work (still relevant)

Tier 1 gift management — addressed gifts pipeline. Buyers can specify recipient name/email/optional note per code at checkout; the WP plugin sends each recipient a personalized HTML email. Migrations 006 + 007. PendingGiftRecipientsRepository for crossing the Stripe-redirect gap.

CF Cache Rule for `/billing/*` + membership paths — eliminates the 404-cache surprises during page-creation cycles.

CF bypass for internal Slim → WP REST calls (CURLOPT_RESOLVE → 127.0.0.1) — fixes the bot-challenge that was hanging up gift emails after CF orange-cloud went on dev.

## What shipped in session 9 (this one)

### Gift page redesign (committed early)
- `[lg_gift]` rebuilt: centered container (fixed "crushed left"), tier cards with "Most popular" badge, − [n] + quantity stepper, preset chips (1/10/20/50 with discount tags), live progress bar to next bulk tier, pricing summary card with savings callout, dynamic CTA ("Continue to checkout · 10 codes · $540"), trust line.

### Plugin-managed pages (Pages.php)
- New `Wp\Pages` class — single source of truth for every shortcode-hosting page (slug, title, template, public/private, in_nav, visibility, nav_label).
- Auto-seed on plugin activation: missing pages inserted, BuddyBoss public-content allowlist auto-populated, rewrite rules + object cache flushed.
- Admin button "Re-create / sync membership pages" for re-running without deactivate/reactivate.
- `[lg_member_nav]` now reads from the registry — `Join` hidden when logged-in, `Manage Subscription` hidden when logged-out.
- `nocache_headers()` sent on shortcode-hosting pages so CF doesn't cache 404s during page-creation cycles.

### Welcome / regional-fail landing pages
- `[lg_subscription_success]` — branches on `?kind=subscription|regional_subscription|membership_annual|gift` and reads tier/qty/expires_at to render kind-specific welcome copy.
- `ReturnHandler` — every success path now sets `redirect_url` so the customer lands on `/welcome/` instead of seeing JSON.
- `APP_RETURN_SUCCESS_URL` env var added.

### Tier 1 gift management — addressed gifts
- Migrations `006_gift_codes_recipients.sql` (recipient_email/name/gift_message/email_sent_at) + `007_gift_recipients_pending.sql` (staging table keyed by Stripe session, varchar(128)).
- `[lg_gift]` "How should the codes get to recipients?" section — toggle between "Send to me" and "Send each recipient directly", per-row repeater with name/email/optional note, paste-list parser ("Name <email>" format), apply-same-note-to-all helper.
- `PendingGiftRecipientsRepository` (interface + Pdo impl) — store on /v1/checkout, consume-and-delete on /v1/return.
- `GiftMailer` rewritten to fan out per-recipient HTML emails using `templates/email/gift-recipient.html.php` (matches the mockup) plus a buyer summary that lists every code with the recipient who got it.
- From-name on recipient emails: "{Giver} via The Looth Group", Reply-To = giver email.
- Smoke-tested via `/v1/checkout` POST: clientSecret returned, 3 recipient rows persisted with correct positions and NULL handling for empty fields.

### Cloudflare cache bypass rule
- Cache Rule deployed (via Ian's CF dashboard) with bypass for both dev + prod hosts on the membership paths + entire `/billing/*` API. Eliminates the "page works in 5 minutes after CF cache TTL" issues during page-creation cycles.

## What shipped in session 8 (this one)

- **`[lg_regional_fail]` shortcode** added in `lg-patreon-stripe-poller`/src/Wp/Shortcodes.php. Reads diagnostic query params (reason, region_tag, billing_country, issuer_country, standard_price_id), renders friendly explanation naming which check tripped, two CTAs (Subscribe at standard pricing → `/lgjoin/?country=US` to bypass regional auto-detect; Contact support → mailto with `lgms_refund_email`).
- WP page created at `/regional-pricing-not-available/` containing `[lg_member_nav][lg_regional_fail]`.
- `APP_REGIONAL_FAIL_URL` on dev `.env` updated from the test console to the new WP page.
- **Discovered BuddyBoss public-content allowlist gating**: pages must be added at WP Admin → BuddyBoss → Settings → General → Public Content, regardless of the `bp-enable-private-network` toggle. Fully documented in PROD-CUTOVER.md including the `wp cache flush` step (BB option is read through object cache).
- Verified end-to-end: failure landing renders correctly with all four diagnostic query params filled in.

### Lessons learned (worth a memory)

- BuddyBoss enforces its public-content allowlist even with private-network mode disabled. Don't trust the surface-level toggle.
- The `wp rewrite flush` doesn't clear the BB option cache — need `wp cache flush` too.
- `url_to_postid()` returns 0 for many published pages even when they resolve fine through `WP_Query`. It's not a reliable indicator of routability — use it for sanity but don't lean on it for diagnosis.

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
