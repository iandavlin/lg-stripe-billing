# Pickup — lg-stripe-billing

*Last worked: 2026-05-05 (session 13)*

## NEXT — End-to-end smoke test of all four custom-mode flows on dev

Session 13 shipped the full Acacia → Basil migration and `ui_mode: 'custom'` for all four checkout flows. The code is deployed to dev but the non-subscription flows (one-time annual, gift, regional verify) haven't been fully browser smoke-tested under custom mode. Do this before any other feature work.

### Test matrix (do all four)

1. **Subscription** — `/lgjoin/`, pick yearly LITE, valid card `4242 4242 4242 4242` → Pay → success redirect to `/activity/` → entitlement + role upgrade + welcome email
2. **3DS subscription** — same path, use `4000 0027 6000 3184` → SCA challenge pops in Payment Element iframe → approve → success
3. **Declined subscription** — `4000 0000 0000 0002` → `confirm()` returns error → inline error shown, X re-enabled, can retry
4. **One-time annual** — pick a one-time price (non-recurring) → custom mode `payment` session → success
5. **Gift** — `/lggift/`, qty 2, confirm custom modal appears, Pay → success → 2 gift codes generated
6. **Regional verify** — use `?country=IN` → setup mode → custom modal → enter IN card `4000 0035 6000 0008` → billing India → pass → subscription created

### Other outstanding items (lower priority)

1. **PayPal activation** — account-level capability `paypal_payments` is absent. Must be enabled at https://dashboard.stripe.com/settings/payment_methods (click "Turn on" for PayPal). No code change needed; once activated it should appear in the Payment Element automatically via the default PMC.
2. **Real-browser end-to-end test of the orphan-charge recovery flow** — Subscribe via `/lgjoin/`, click Pay, close tab before redirect. Webhook should fire → entitlement + email sent. Confirm email lands.
3. **`charge.refunded` webhook** — handler is in code (registered for dev endpoint). Register on prod at cutover. PROD-CUTOVER.md already lists it.
4. **Tier 2 Phase D (gift management buttons)** — Send / Resend / Reassign / Void wiring on `[lg_my_gifts]`. Slim endpoints already shipped; WP-side UI is still stubbed.
5. **Tier 2 Phase C (auto-account creation for non-member gift buyers)** — qty ≥ 4 "create login + manage from dashboard" mode.
6. **Production cutover.** PROD-CUTOVER.md has the full checklist.

## What shipped in session 13 (this one)

### Stripe Acacia → Basil migration

- **`LiveStripeGateway.php`** — API version pin changed: `2024-12-18.acacia` → `2025-03-31.basil`
- **`CheckoutService.php`** — all four session-creation methods (`createSubscriptionSession`, `createOneTimeMembershipSession`, `createGiftCheckoutSession`, `createRegionalSetupSession`) switched from `'ui_mode' => 'embedded'` to `'ui_mode' => 'custom'`. All return `['clientSecret' => ..., 'ui_mode' => 'custom']`.
- **`PROD-CUTOVER.md`** — `APP_RETURN_SUCCESS_URL` guidance updated: now points to `/activity/` (BuddyBoss feed), not `/welcome/`.

### WP plugin (`lg-patreon-stripe-poller`) — `ui_mode: 'custom'` client integration

Both `[lg_join]` and `[lg_gift]` shortcodes migrated to custom checkout mode. `LGPO_VERSION` bumped `2.0.4 → 2.3.2` across the session for cache-busting.

**Stripe.js**: Both shortcodes changed from `https://js.stripe.com/v3/` to `https://js.stripe.com/basil/stripe.js`.

**Modal HTML**: Both modals gained:
- `data-lg-join-modal-processing` / `data-lg-gift-modal-processing` — fullscreen spinner overlay while session loads and while confirm() runs
- `.lg-stripe-modal` custom block — Stripe-branded header (amount, sublabel, Stripe wordmark), Payment Element mount div, inline error div, purple Pay button

**JS — `lg_join`**: Variables `customCheckout`, `paymentElement`, `modalProcessingEl`, `payAmountEl`, `paySublabelEl`. Helper functions:
- `teardownCheckoutMount()` — destroys Payment Element + custom checkout (kills Stripe's beforeunload before any redirect)
- `mountCustomCheckout()` — calls `stripe.initCheckout({ fetchClientSecret })`, listens for `change` to populate amount + enable/disable Pay button, mounts Payment Element, reveals form on `paymentElement.on('ready', ...)`
- `onPayClick()` — locks modal, shows spinner, calls `checkout.confirm()`, on success: hides modal + `teardownCheckoutMount()` + `window.location.href = /billing/v1/return?session_id=...`; on error: removes lock, hides spinner, shows inline error

**JS — `lg_gift`**: Mirror of the above (`giftCustomCheckout`, `giftPaymentElement`, etc.), `teardownGiftMount()`, `mountGiftCustomCheckout()`, `onGiftPayClick()`.

**CSS additions**: `.lg-stripe-modal` layout, `.lg-stripe-modal__pay` purple `#635BFF` Pay button (multi-selector + chained `!important` to override Elementor), `.lg-modal-processing` overlay, `@keyframes lg-pay-spin` spinner.

### UX improvements shipped during dev iteration

- **"Leave site?" suppressed**: after `confirm()` success, `teardownCheckoutMount()` destroys the Payment Element (and its `beforeunload` listener) before `window.location.href` fires. No browser prompt.
- **Pay button / form sync**: custom block stays hidden + spinner shown until `paymentElement.on('ready', ...)` fires. Eliminates the ~1s window where Pay button was visible before Stripe iframe painted.
- **Post-pay redirect target**: changed from `/welcome/` to `/activity/` (BuddyBoss member feed).
- **Welcome modal simplified**: `Plugin::maybePrintWelcomeModal()` — removed "Manage subscription" and "Got it →" buttons, added corner X close button (`.lg-welcome-modal__close`). Removed `is_page('welcome')` skip (no longer a redirect target).
- **Header padding reduced**: `.lg-stripe-modal__header` padding tightened from default to `12px 20px`.
- **Stripe branding**: Stripe wordmark SVG + "Powered by Stripe — Secure payment" trust line added to both join and gift modals.
- **NBSP placeholder fix**: empty amount/sublabel elements used `&nbsp;` as height placeholder, causing ghost rows. Fixed via `[hidden] { display: none }` CSS + JS sets `.hidden = !value`. NBSP chars in file required perl one-liners to replace (Edit tool couldn't match UTF-8 `0xC2 0xA0`).

### PayPal investigation

Account-level `paypal_payments` capability is absent — PayPal is not in any of the 5 Payment Method Configurations and doesn't appear in the Stripe Dashboard payment methods list. Requires Dashboard activation at https://dashboard.stripe.com/settings/payment_methods. No code change needed on our side.

---

## What shipped in session 12 (this one)

### Recovery operation (started the session deep in a hole)

Discovered mid-session that prior `scp` deploys had been overwriting commits on dev that never made it back to local or GitHub. Dev's `.git` had ~6 Slim commits and ~43 WP plugin commits ahead of origin (the orphan-charge architecture, looth1 starter tier, welcome modal, password UX, etc.) that the local working tree had no knowledge of. Each new `scp` from local to dev was clobbering those commits' deployed files.

Recovery:
- Snapshotted dev's clobbered uncommitted diff (641KB WP + 28KB Slim) to `_recovery_20260505_074347/` for forensics.
- Restored dev's working tree to committed HEAD via `git checkout HEAD -- <files>` (preserved untracked new files).
- Pushed dev's commits to GitHub (Slim required a small merge with origin's PICKUP-only diverging commits, then push).
- Pulled to local — local + dev + GitHub now all in sync.
- Removed orphan files I'd added on top of dev (`RefundWebhookHandler.php`, `WpCustomerMailer.php`, `AccessStatusController.php`, `customer-notice.html.php`) — they were trying to bolt onto the architecture I'd assumed existed (which was actually the older state). Saved them to `_recovery_*/orphan_files/` for later evaluation against the recovered architecture.

**Lesson:** session 12 wrote a memory at `~/.claude/projects/.../memory/process_deploy_via_pull_not_scp.md` (or should — see PICKUP-NOTE) about always `git pull` on dev before deploying, never `scp` files into a git checkout without first verifying local is at or ahead of dev's HEAD.

### `[lg_join]` form polish

After recovery, the only code shipped this session was UX polish on the join page. All committed (`fa3b78a` or similar — see git log).

- **Confirm-email field** added next to email; live "Emails don't match." mirror of the existing password-mismatch UX.
- **Form reorder** — was Email | Profile name | Password | Confirm password. Now: Email | Confirm email / Password | Confirm password / Profile name (full-width, spans both columns via `grid-column: 1 / -1`).
- **Default tier selection on page load** — `[lg_join]` opens with the popular tier's yearly price pre-selected (form panel revealed, button shows orange selected state). `selectPrice()` gained a `{ silent: true }` opt to skip scroll-into-view + auto-focus when the auto-pick fires, so the tier cards stay visible above the form on initial render.
- **Button visual treatment** in `assets/lg-shortcodes.css`:
  - All `.lg-join__buy` get a baseline drop shadow (raised, clickable look).
  - `.lg-join__buy.is-primary` (suggested yearly): enhanced amber-tinted shadow + 1px lift, but no orange fill. Pulls the eye without claiming the spotlight.
  - `.lg-join__buy.is-selected`: full orange + white text. Unmistakable. Applied via JS to whichever button the user clicks (clears all sibling `.is-selected` across all tier cards so only one across the whole page is active).
- **X stays live until `onComplete`** — `joinCheckoutModal.dataset.lgLocked = '1'` and `markJoinPaymentInFlight()` moved out of "right after `mountedSession.mount()`" into `showJoinProcessingOverlay()` (which Stripe fires via `onComplete` only after a successful charge). Until that runs, the X / Esc / backdrop all work normally so users can back out cleanly. Orphan-charge recovery still catches the close-after-Pay edge case so even if they close mid-redirect they get their sub.
- **Existing-account modal markup duplicated into `[lg_join]`.** It used to live only inside `[lg_gift]` (line 472), so pages hosting only `[lg_join]` had no DOM for the modal to attach to — `existAcctModal` was `null` and the "this email already has an account" prompt silently no-op'd. Now both shortcodes render their own copy with distinct `aria-labelledby` IDs.
- **`LGPO_VERSION` 2.0.0 → 2.0.4** for cache-busting across each polish iteration.

## What shipped in session 11 (this one)

### Orphan-charge recovery architecture (Slim + WP)

The recurring symptom: customer pays through Stripe, browser dies between Pay click and the `/v1/return` redirect (modal close, network drop, crash, phone sleep), Stripe records the charge, our DB records nothing. Stripe documentation explicitly recommends not relying on the landing page for fulfillment; this session builds the recommended hybrid.

**Slim (`lg-stripe-billing`)**

- **Migration 008** `pending_sessions` table — records every Stripe Checkout session at creation time, marks resolved on successful provisioning. Three columns + a unique index on `session_id`.
- **`PdoPendingSessionRepository`** — `record() / markResolved() / touchPolled() / listUnresolvedOlderThan() / pruneOlderThan()`.
- **`CheckoutService`** — every session-creation method (subscription, one-time, gift, regional-setup) calls `pending->record()` after Stripe returns. Five callsites, each with a kind tag for audit clarity.
- **`ReturnHandler::handle`** — rewrapped to capture the per-mode result, then `pending->markResolved($id, 'returned')` on success. Failure paths leave the row unresolved so the cron sweep can retry.
- **`/v1/reconcile-pending` (`ReconciliationController`)** — auth'd via `X-LGMS-Token`; sweeps unresolved rows older than 60s, polls Stripe per row, dispatches `ReturnHandler::handle` for completed sessions, marks expired rows abandoned, leaves open rows for the next sweep. Prunes resolved rows older than 7 days each pass.
- **`WebhookController::handleCheckoutCompleted`** — new case in the dispatch match. Routes to the same idempotent `ReturnHandler::handle`, so the webhook fast-path and the cron polling sweep both converge on the same provisioning logic. Errors are swallowed and logged so we always return 200 to Stripe (no exponential-backoff retries masking real causes).
- **Subscribed dev's webhook to `checkout.session.completed`** via Stripe API.

**WP plugin (`lg-patreon-stripe-poller`)**

- **`Tick.php` Pass 1.7** — POST to `/v1/reconcile-pending` every cron tick. Uses raw `curl` with `CURLOPT_RESOLVE` pinned to 127.0.0.1 (mirroring the same trick `WpSync` uses for the reverse direction; `wp_remote_post` would hit the Cloudflare bot-challenge that intercepts loopback PHP-curl requests).
- **5-minute custom cron schedule** (`lgms_5min`) registered via `cron_schedules` filter. `Plugin::CRON_SCHEDULE` flipped from `hourly`. `Plugin::maybeRescheduleCron` (init priority 99) self-heals existing installs that were scheduled on the old interval — no deactivate/reactivate needed.

### looth1 role rework — starter tier instead of lapsed-paid

Previously `looth1` was reserved for users who lapsed out of a paid tier; gift-auth signups landed in the legacy WC `customer` role with no member access. Bailed-checkout users ended up with an account that did nothing useful. New model: `looth1` is the starter tier from sign-up; Arbiter promotes to `looth2/3/4` on paid entitlement.

- **Code-snippets snippet #90** ("Log Out Looth 1 Users Immediately") **deactivated**. Snippets #88 and #89 (older lockout iterations) were already inactive.
- **`RestController::giftAuth`** — new accounts mint as `looth1` (was `customer`). Existing-user login no longer demotes `looth1 → customer` (was a yo-yo pattern under the new model). Docstring updated.
- **`Arbiter::sync`** — captures the old tier before the role-rewrite, detects `looth1 → looth2/3/4` upgrade transitions. On detection, sets `_lg_pending_welcome` user meta and fires `WelcomeMailer::sendIfNeeded`.

### Welcome modal + welcome email

- **`Plugin::maybePrintWelcomeModal`** (`wp_footer` hook) — renders a one-shot celebratory modal when `_lg_pending_welcome` is set. Slides up from bottom over a dimmed backdrop, max z-index, amber accent matching site style. Skips wp-admin / AJAX / `/welcome/` itself. Two actions: "Manage subscription" link and "Got it →" dismiss.
- **`/lg-member-sync/v1/dismiss-welcome`** REST endpoint — clears the meta on dismiss. Auth: logged-in user + REST nonce.
- **`LGMS\Wp\WelcomeMailer::sendIfNeeded($wpUserId, $tier)`** — fires from `Arbiter::sync` on the same upgrade transition. Idempotent via separate `_lg_welcome_email_sent_at` user meta sentinel — exactly one email per user even across many cron passes. Template at `templates/email/welcome-membership.html.php` (amber-accented HTML matching the modal).

### Checkout UX polish (committed earlier in the session)

- **Subtle password reveal** — replaced the default browser button on `/lgjoin/` (which rendered as a giant blue square via the BB theme button styling) with an inline heroicons-style eye SVG inside the input. Password and Confirm-password inputs now share form grid columns so they line up with email and profile-name above. CSS that was scoped under `[lg_gift]`'s style block is now duplicated into the `[lg_join]` style block so the rules actually apply.
- **Post-pay processing overlay** — Stripe `onComplete` triggers a fullscreen overlay until the redirect lands.
- **Modal lockdown** once the iframe is mounted (`data-lg-locked="1"`) — X button hidden, backdrop click-through. Pre-mount the X still works so users can back out before committing.
- **`beforeunload` removed on `onComplete`** so Stripe's intended redirect doesn't trigger the "Leave site?" prompt.

These are belt-and-suspenders measures; the orphan-recovery architecture is the actual safety net. They reduce the likelihood of needing to fall back to it.

### Architecture / debug fixes shipped along the way

- **Rewrite flush deferred to `init` priority 9999** (`Plugin.php` + `lg-patreon-onboard.php` + `Pages.php`). Both activation hooks were calling `flush_rewrite_rules()` mid-activation before `init` had fired, serializing partial rule sets into the `rewrite_rules` option and producing intermittent 404s on top-level pages. Activation hooks now set a transient flag; the deferred handler flushes once on the next `init` after every plugin has registered its rules. `Pages::ensureAll` only sets the flag when state actually changed; `wp_cache_flush()` replaced with targeted `wp_cache_delete('alloptions', 'options')` to avoid global cache wipes mid-request.
- **Admin-pages 502 root cause:** Ian's admin user (`iandavlin`, id 1) had triple role stacking — `administrator + looth2 + bbp_participant` — which caused FPM segfault-class behavior on every regular page render (homepage worked because `is_front_page()` short-circuits a lot of chrome). Stripped to `administrator + bbp_keymaster`; pages now render. Worth flagging on prod for any other admins with stacked roles.
- **`pending_sessions` reconcile cURL bypass** — `wp_remote_post` to `/billing/v1/reconcile-pending` hit Cloudflare's bot challenge (HTTP 403 challenge page). Replaced with raw cURL + `CURLOPT_RESOLVE → 127.0.0.1`.
- **Test-account hygiene** — `ianhatesguitars@*` accounts and customer 32/33/34/35/36/40 nuked across multiple cycles; ian.davlin de-gifted (gift_codes purchased/redeemed, gift-source entitlements, `_lgms_has_gifts` meta cleared); orphaned Stripe subs canceled. Documented the pattern for future cleanups.

### Lessons / things-worth-remembering

- **Stripe Embedded Checkout's parent-page redirect is fundamentally fragile.** Anything that kills the iframe between Pay-click and the redirect (modal close, browser crash, network drop) leaves you charged but unfulfilled. Stripe's docs recommend `checkout.session.completed` webhook for fulfillment, with `return_url` as a UX-only confirmation page. We now do both.
- **WP cron only fires when WordPress serves a request.** `/billing/...` curls bypass WP entirely (it's Slim under nginx alias) and don't trigger `wp-cron.php`. Without OS cron hitting `wp-cron.php`, the dev box's polling sweep never fires unattended. PROD-CUTOVER step 4 makes this explicit for prod.
- **Forever-valid magic links are a defensible product choice** for a membership site. The prior magic-link refactor option was deferred in favor of pre-Stripe auth + post-pay welcome modal/email — simpler code path, account exists with the password the user typed, no token expiry to manage.
- **Triple-stacked WP roles can cause silent FPM crashes.** `administrator + looth2 + bbp_participant` was producing 502 on every non-front-page render until trimmed.

## What shipped in session 9

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
