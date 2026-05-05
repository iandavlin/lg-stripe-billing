# Purchase scenario matrix

*Last updated: 2026-05-05 (session 14)*

Map of every buyer state × purchase intent the Looth Group billing
stack faces, with current handling status and known gaps. Mirrored in
`lg-patreon-stripe-poller/docs/purchase-scenarios.md` so both repos
have the same reference.

The key axes are **buyer state** × **purchase intent** × **failure
mode**. Grouped by buyer state since that's where most of the
conditional logic lives.

Legend: ✅ handled · ⚠️ partial / fragile · ❌ not handled

---

## Buyer state × purchase intent

### A. Anonymous buyer (no WP login, email unknown to system)

| Intent | Behavior | Status |
|---|---|---|
| Subscribe (standard) | `[lg_join]` collects email/password/profile name → `/v1/checkout` → custom modal → `looth1` account minted at success → Arbiter promotes to `looth2/3` | ✅ |
| Subscribe (regional) | Setup-mode session → billing+issuer country verify → pass: subscription created · fail: PM detached + redirect to `[lg_regional_fail]` | ✅ |
| One-time annual | Payment-mode session → entitlement with `expires_at = now + 365d` | ✅ |
| Gift, qty 1 | Quantity stepper requires ≥ 2 (gift_min) | ⚠️ enforced UI-side only — see gaps |
| Gift, qty ≥ 2 (self) | Anon-warn modal → checkout → success modal "codes en route to <email>" | ✅ |
| Gift, qty ≥ 2 (addressed recipients) | Per-row name+email collected → `pending_gift_recipients` → fanned-out emails on success | ✅ |
| Redeem a gift code | `[lg_redeem_gift]` → `/v1/redeem` → grants entitlement, mints `looth1` if no account | ✅ |

### B. Anonymous buyer, but email matches an existing WP user

| Intent | Behavior | Status |
|---|---|---|
| Subscribe, types correct password | Auth via `/lg-member-sync/v1/gift-auth` → proceeds as logged-in | ✅ |
| Subscribe, wrong password | `lg-existacct` modal: "Log in & manage subscription" / "Forgot password" / "Use different email" | ✅ |
| Subscribe with email of customer who has active sub on file | `/v1/checkout` returns 409 `has_active_sub` | ✅ but UI handling of 409 is generic error toast — see gaps |
| Subscribe with email of customer who has active **gift** | Returns `needs_gift_confirmation` → `lg-giftwarn` modal asks "stack subscription on top of gift?" | ✅ |
| Subscribe with email of `blocked` customer | 403 "not eligible" | ✅ but no support-contact CTA in error |
| Buy gift (self) | `isGift=true` bypasses the active-sub guard — anyone can buy gifts | ✅ intentional |

### C. Logged-in WP user, no past purchases

| Intent | Behavior | Status |
|---|---|---|
| Subscribe | Email pre-filled, no auth panel; same custom-modal flow | ✅ |
| One-time annual | Same | ✅ |
| Regional sub | Same | ✅ |
| Buy gift | Manage-mode pre-selected (logged-in path); recipients optional; success modal pops; gift codes appear in `[lg_my_gifts]` | ✅ |
| Redeem gift | `[lg_redeem_gift]` → entitlement attaches to existing customer | ✅ |

### D. Logged-in user with active subscription

| Intent | Behavior | Status |
|---|---|---|
| Subscribe to **same** tier/interval | 409 `has_active_sub` | ✅ but no "you already have this" hint |
| Subscribe to **higher** tier (LITE → PRO) | 409 — blocked | ⚠️ no upgrade flow — see gaps |
| Subscribe to **lower** tier (PRO → LITE) | 409 — blocked | ⚠️ no downgrade flow — see gaps |
| Switch billing interval (monthly → yearly) | 409 — blocked | ⚠️ no interval-switch flow — see gaps |
| One-time annual on top of active sub | 409 (same guard hits non-gift paths) | ✅ correct |
| Buy gift for someone else | Allowed, success modal | ✅ |
| Buy gift for self | Allowed (anti-pattern but not blocked) | ⚠️ probably fine but low-effort to discourage |
| Cancel subscription | Stripe Customer Portal | ✅ via `/v1/portal` |
| Manage payment method | Stripe Customer Portal | ✅ |

### E. Logged-in user, has active **gift** entitlement (not paid sub)

| Intent | Behavior | Status |
|---|---|---|
| Subscribe (any tier) | `needs_gift_confirmation` → `lg-giftwarn` modal → ack=true allows checkout — sub stacks on gift days | ✅ |
| Subscribe without ack | Blocked → modal pops | ✅ |
| Buy more gifts | Allowed | ✅ |
| Redeem another gift code | Stacks via `GiftRedemptionService` strategy (stack/prorate) | ✅ |

### F. Logged-in user, **lapsed** sub (`looth1`)

| Intent | Behavior | Status |
|---|---|---|
| Re-subscribe | No active sub on file → proceeds normally; old canceled sub remains in `subscriptions` history | ✅ |
| Buy gift | Allowed | ✅ |

### G. Logged-in user with **past_due** sub

| Intent | Behavior | Status |
|---|---|---|
| Subscribe (new) | 409 — `past_due` is in `ACTIVE_STATUSES` | ✅ correct (Stripe is mid-retry) |
| Pay invoice | Customer Portal handles | ✅ |
| Stripe gives up and cancels | Webhook flips to `canceled` → entitlement revoked | ✅ |

---

## Purchase-time failure modes (orthogonal to buyer state)

| Failure | Current handling | Status |
|---|---|---|
| Card declined at `confirm()` | Inline error in modal, X re-enabled, retry possible | ✅ |
| 3DS challenge | Stripe Payment Element handles natively | ✅ |
| 3DS abandoned/timeout | Treated as confirm() error — retry flow | ✅ |
| Network drop after `confirm()` succeeds | Browser hits webhook (if it lands) and reconcile cron picks up within 5 min — orphan-charge architecture handles | ✅ |
| User closes tab between Pay and `/v1/return` | Same as above | ✅ |
| User closes modal mid-Pay (Stripe already submitted to bank) | `paymentInFlight` lock + locked-modal CSS — X hidden, backdrop disabled | ✅ |
| Stripe webhook fails to verify signature | 400 returned, Stripe retries | ✅ |
| `/v1/return` errors during provisioning | Row stays `unresolved` in `pending_sessions` → cron retries | ✅ |
| Two browser tabs, same buyer, simultaneous checkouts | Each gets separate session; first to complete provisions; second hits `has_active_sub` 409 | ✅ |
| Promo code makes price $0 | Stripe handles via $0 invoice; sub created with `trial`-like behavior | ⚠️ untested |
| Promo code expired/invalid | Stripe rejects with error from `confirm()` | ✅ |

---

## Bonus — gaps worth attention

### Real product holes (worth fixing)

1. **No upgrade / downgrade / interval-switch path.** Active LITE
   subscriber who wants PRO has to cancel, wait for period end, then
   re-subscribe — and the 409 error message tells them "manage your
   plan" but there's no actual UI to swap tiers. The Stripe Customer
   Portal can be configured to allow plan switches; that's the easy
   fix, but it needs to be enabled in Dashboard portal config and the
   link surfaced more prominently in the 409 response handler.

2. **`charge.refunded` webhook not registered on dev.** Handler isn't
   in the `WebhookController::handle` match block. If you issue a
   refund from Stripe Dashboard right now, the entitlement stays
   granted until the linked subscription is canceled. **Real bug.**
   Code says "refunded → revoke immediately, all cases" but nothing
   fires.

3. **`charge.dispute.created` (chargeback) not handled at all.** A
   chargeback should at minimum email-alert and probably revoke access
   pending dispute resolution. Today: nothing happens.

4. **`invoice.payment_failed` not handled.** When a renewal fails,
   Stripe keeps the sub at `active` until it transitions to `past_due`
   after retries. We don't surface "your card failed, update it" to
   the user proactively. Today: silent until they come back to manage
   subscription.

5. **`customer.subscription.created` not in webhook match.** Most
   flows hit `/v1/return` first so it's fine, but a sub created
   out-of-band (Dashboard, recovery, future API integration) wouldn't
   provision until the cron sweep runs.

6. **Server doesn't enforce `quantity >= 2` on gift purchases.**
   UI-side only. A crafted POST to `/v1/checkout` with `gift=true,
   quantity=1` would create a session at base price with no bulk-
   discount logic and ship a single code. Not exploitable for free
   stuff (Stripe still charges) but the bulk-tier pricing assumes ≥ 2.

7. **No idempotency key on `/v1/checkout`.** Double-click on Continue
   could fire two requests on flaky networks → two `pending_sessions`
   rows + two Stripe sessions. The second orphans when only one is
   completed.

### Edge cases that probably "work" but haven't been tested deliberately

8. **Gift recipient redeems a code while logged in as a different
   email.** The code attaches to *which* customer? Need to verify
   `RedeemController` matches on the redeemer's email or the
   logged-in user's email — these can disagree.

9. **Same gift code redeemed twice in a race.** `gift_codes.redeemed_by`
   should be guarded by a unique constraint or row-level lock;
   otherwise two simultaneous redeems could both succeed.

10. **Anon buyer enters an email that already has a subscription
    canceled-but-not-deleted in Stripe with a stored PM.** The flow
    creates a fresh customer record on our side or matches the
    existing one? `findByEmail` returns existing → but the active-sub
    guard sees `canceled` is not active → checkout proceeds → Stripe
    creates a new customer or attaches to the existing one based on
    email match. Probably fine but worth a deliberate test.

11. **Anon gift purchase where buyer email later signs up.** Two
    separate customer rows for the same email, one with gift purchase
    history, one with a sub. The `customers.email` UNIQUE constraint
    (if present) would have blocked it; if not, you have a split-
    history bug.

12. **Currency / non-US buyer using non-USD card.** Memory says
    USD-only. Stripe auto-FX charges at the card's native currency
    with conversion. Confirmed in regional test matrix as $40 for IN
    card; broader country coverage untested.

13. **Subscription renewal that hits the regional verify boundary
    later.** A user verified as eligible at signup who later changes
    billing country in the Customer Portal — does that affect their
    renewals? Today nothing re-checks. Probably acceptable but worth
    knowing.

14. **Buyer with active sub clicks "buy gift" and self-mode is
    pre-selected.** Gift purchase is allowed for active subs
    (correct), but the gift codes go to the buyer's email by default
    in self-mode. If they redeem one, gift redemption stacks on their
    own sub. Not broken, just weird — and on the path to "users gaming
    the bulk discount to renew themselves cheaper." If 50× $1 gift
    codes is cheaper than 50 years of standard pricing, there is an
    arbitrage worth modeling.

### Smaller polish gaps

15. **409 `has_active_sub` response from `/v1/checkout`** — UI
    surfaces a generic "Could not start checkout" message rather than
    parsing the structured response and routing to "manage
    subscription" CTA.

16. **Blocked-customer 403** — same; no specific UI for "contact
    support."

17. **Welcome email after gift redemption** — currently fires only
    on subscription tier upgrade transitions per `Arbiter::sync`. If a
    user redeems a gift to go from `looth1 → looth2`, that *should*
    trigger the welcome path; verify the upgrade detection covers that
    source.

18. **Failed regional verify** redirects to `[lg_regional_fail]` but
    the page does not pre-emptively offer the standard product price
    card. User has to click through to `?country=US` override or
    contact support. Likely hurts conversion in the failure state.

---

## Recommended priorities

1. Register `charge.refunded` (real bug, real money).
2. Add a portal-link CTA for tier-swap (real product hole).
3. Server-enforce `quantity >= 2` on gift checkout (defensive).

Everything else is backlog-acceptable.
