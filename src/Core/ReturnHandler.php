<?php

declare(strict_types=1);

namespace LGSB\Core;

use DateTimeImmutable;
use LGSB\Adapters\PdoPendingSessionRepository;
use LGSB\Contracts\SettingsStore;
use LGSB\Domain\Repositories\AdminActionLogRepository;
use LGSB\Domain\Repositories\AffiliateRepository;
use LGSB\Domain\Repositories\GiftCodeRepository;
use LGSB\Domain\Repositories\PendingGiftRecipientsRepository;
use LGSB\Domain\Repositories\ProductRepository;
use LGSB\Domain\Repositories\SubscriptionRepository;
use LGSB\Stripe\StripeGateway;

/**
 * Handles the synchronous return URL after Stripe Checkout.
 *
 * Dispatch by mode + checkout_type metadata:
 *   subscription              → provision customer + subscription + entitlement
 *   payment / gift            → generate N gift codes, email to purchaser
 *   payment / membership_annual → fixed-duration entitlement (no Stripe subscription)
 *   setup   / regional_verify → verify billing country vs price_regions;
 *                                pass → create subscription;
 *                                fail → detach PM, redirect to failure page
 */
class ReturnHandler
{
    public function __construct(
        private readonly StripeGateway                   $stripe,
        private readonly ProductRepository               $products,
        private readonly CustomerManager                 $customers,
        private readonly SubscriptionRepository          $subscriptions,
        private readonly EntitlementManager              $entitlements,
        private readonly GiftCodeRepository              $giftCodes,
        private readonly WpGiftMailer                    $mailer,
        private readonly WpSync                          $wpSync,
        private readonly SettingsStore                   $settings,
        private readonly AdminActionLogRepository        $auditLog,
        private readonly PendingGiftRecipientsRepository $pendingRecipients,
        private readonly PdoPendingSessionRepository     $pending,
        private readonly AffiliateRepository             $affiliates,
    ) {}

    /**
     * @return array{ok:bool,message:string,customer_id?:int,tier?:string,quantity?:int,redirect_url?:string}
     */
    public function handle(string $sessionId): array
    {
        $session = $this->stripe->retrieveCheckoutSession($sessionId, [
            'subscription',
            'subscription.items.data.price',
            'setup_intent',
            'setup_intent.payment_method',
        ]);

        if (($session->status ?? '') !== 'complete') {
            return [
                'ok'      => false,
                'message' => 'Session not complete: ' . ((string) ($session->status ?? 'unknown')),
            ];
        }

        $mode = (string) ($session->mode ?? '');

        if ($mode === 'setup') {
            $checkoutType = (string) ($session->metadata->checkout_type ?? '');
            $result = match ($checkoutType) {
                'regional_verify' => $this->handleRegionalVerify($session),
                default           => ['ok' => false, 'message' => "Unknown setup checkout type: {$checkoutType}."],
            };
        } elseif ($mode === 'payment') {
            $checkoutType = (string) ($session->metadata->checkout_type ?? '');
            $result = match ($checkoutType) {
                'gift'              => $this->handleGift($session),
                'membership_annual' => $this->handleOneTimeMembership($session),
                default             => ['ok' => false, 'message' => "Unknown payment checkout type: {$checkoutType}."],
            };
        } elseif ($mode === 'subscription') {
            $result = $this->handleSubscription($session);
        } else {
            $result = ['ok' => false, 'message' => "Unhandled checkout mode: {$mode}."];
        }

        // Mark the pending_sessions row resolved iff provisioning succeeded.
        // Failures leave the row unresolved so the cron sweep can retry it
        // on subsequent passes (e.g. transient WP unreachability resolves
        // on its own without manual intervention).
        if ($result['ok'] ?? false) {
            $this->pending->markResolved($sessionId, 'returned');
        }

        return $result;
    }

    /**
     * Regional billing-country verification (setup mode).
     *
     * Retrieves the saved payment method from the completed Setup Intent,
     * reads its billing country, and checks it against price_regions for
     * the price's region_tag.
     *
     * Pass → create subscription immediately using the saved PM.
     * Fail → detach the PM (no charge ever made), return redirect_url so the
     *         controller can send the browser to the failure page.
     */
    private function handleRegionalVerify(object $session): array
    {
        $meta      = $session->metadata;
        $regionTag = (string) ($meta->region_tag ?? '');
        $priceId   = (string) ($meta->price_id ?? '');
        $tier      = (string) ($meta->tier ?? '');

        if ($regionTag === '' || $priceId === '' || $tier === '') {
            return ['ok' => false, 'message' => 'Setup session missing required metadata.'];
        }

        // setup_intent + payment_method are expanded at retrieve time.
        $si = $session->setup_intent;
        if (!is_object($si)) {
            return ['ok' => false, 'message' => 'No setup intent on session.'];
        }
        $pm = $si->payment_method;
        if (!is_object($pm)) {
            return ['ok' => false, 'message' => 'No payment method on setup intent.'];
        }
        $pmId           = (string) $pm->id;
        $billingCountry = strtoupper((string) ($pm->billing_details->address->country ?? ''));
        // Card issuer country = the bank's country, set by Stripe from the BIN —
        // NOT user-entered. Required as a second factor to defeat the trivial
        // "type IN as my billing address" arbitrage path: getting an Indian-
        // issued credit card is real-world friction that an arbitrageur won't
        // pay just to save $30/year.
        $issuerCountry  = strtoupper((string) ($pm->card->country ?? ''));

        // Resolve / create customer.
        $stripeCustomerId = (string) ($session->customer ?? '');
        $email            = (string) ($session->customer_details->email ?? $session->customer_email ?? '');
        $name             = trim((string) ($session->customer_details->name ?? ''));

        if ($email === '') {
            return ['ok' => false, 'message' => 'Setup session missing customer email.'];
        }

        $customer = $this->customers->findOrCreate(
            $email,
            $stripeCustomerId !== '' ? $stripeCustomerId : null,
            $name ?: null,
            $billingCountry ?: null,
        );

        // Eligibility: BOTH billing address AND card issuer must be in the region.
        // Billing country alone is user-entered metadata Stripe doesn't validate;
        // the issuer country is from the BIN and can't be spoofed.
        $billingOk = $billingCountry !== ''
            && $this->products->countryInRegion($billingCountry, $regionTag);
        $issuerOk  = $issuerCountry !== ''
            && $this->products->countryInRegion($issuerCountry, $regionTag);
        $eligible  = $billingOk && $issuerOk;

        // Audit every attempt — success and failure.
        $this->logVerification($customer->id, $priceId, $regionTag, $billingCountry, $issuerCountry, $eligible);

        if (!$eligible) {
            // Detach the PM so it can't be charged; no money was ever moved.
            $this->stripe->detachPaymentMethod($pmId);

            $standardPriceId = $this->products->standardPriceForTierAndInterval($priceId);
            $failBase        = $this->settings->getRegionalFailUrl();
            $sep             = str_contains($failBase, '?') ? '&' : '?';
            $failUrl         = $failBase . $sep . http_build_query(array_filter([
                'reason'            => 'region_mismatch',
                'region_tag'        => $regionTag,
                'billing_country'   => $billingCountry,
                'issuer_country'    => $issuerCountry,
                'standard_price_id' => $standardPriceId,
            ]));

            $reason = !$billingOk ? "billing country {$billingCountry}" : "card issuer country {$issuerCountry}";
            return [
                'ok'           => false,
                'message'      => "Not eligible for {$regionTag} pricing — {$reason} doesn't match.",
                'redirect_url' => $failUrl,
            ];
        }

        // Pass: create the subscription using the verified PM.
        if ($customer->stripeCustomerId === null) {
            return ['ok' => false, 'message' => 'Customer has no Stripe ID after setup session.'];
        }

        $stripeSub = $this->stripe->createSubscription(
            $customer->stripeCustomerId,
            $priceId,
            $pmId,
        );

        $sub = $this->subscriptions->upsert(
            $customer->id,
            (string) $stripeSub->id,
            $priceId,
            (string) ($stripeSub->status ?? ''),
            (bool) ($stripeSub->cancel_at_period_end ?? false),
            self::tsToDate($stripeSub->current_period_start ?? null),
            self::tsToDate($stripeSub->current_period_end ?? null),
            self::tsToDate($stripeSub->canceled_at ?? null),
        );

        $this->entitlements->grantMembershipFromSubscription($customer->id, $tier, $sub->id);
        $this->wpSync->trigger($customer->id);
        $this->recordAffiliateConversion($session, $customer->id, $tier);

        return [
            'ok'           => true,
            'message'      => "Regional subscription provisioned for {$customer->email} → {$tier}",
            'customer_id'  => $customer->id,
            'tier'         => $tier,
            'redirect_url' => $this->buildSuccessUrl([
                'kind' => 'regional_subscription',
                'tier' => $tier,
            ]),
        ];
    }

    /**
     * Handle a one-time annual membership purchase. Customer paid for a fixed
     * duration (no Stripe subscription); we grant an entitlement with explicit
     * expires_at and source_type='order'.
     */
    private function handleOneTimeMembership(object $session): array
    {
        $meta = $session->metadata;
        $stripeCustomerId = (string) ($session->customer ?? '');
        $email            = (string) ($session->customer_details->email ?? $session->customer_email ?? '');
        $name             = trim((string) ($session->customer_details->name ?? ''));
        $country          = $session->customer_details->address->country ?? null;
        $tier             = (string) ($meta->tier ?? '');
        $durationDays     = max(1, (int) ($meta->duration_days ?? 365));

        if ($email === '' || $tier === '') {
            return ['ok' => false, 'message' => 'Membership session missing email or tier.'];
        }

        $customer = $this->customers->findOrCreate(
            $email,
            $stripeCustomerId !== '' ? $stripeCustomerId : null,
            $name ?: null,
            $country,
        );

        $expiresAt = (new DateTimeImmutable())->add(new \DateInterval("P{$durationDays}D"));

        $this->entitlements->grantMembershipFromOrder(
            $customer->id,
            $tier,
            (int) ($session->id !== null ? crc32((string) $session->id) : 0),
            $expiresAt,
        );

        $this->wpSync->trigger($customer->id);
        $this->recordAffiliateConversion($session, $customer->id, $tier);

        return [
            'ok'           => true,
            'message'      => "Provisioned {$customer->email} → {$tier} for {$durationDays} days",
            'customer_id'  => $customer->id,
            'tier'         => $tier,
            'expires_at'   => $expiresAt->format('Y-m-d'),
            'redirect_url' => $this->buildSuccessUrl([
                'kind'       => 'membership_annual',
                'tier'       => $tier,
                'expires_at' => $expiresAt->format('Y-m-d'),
            ]),
        ];
    }

    private function handleSubscription(object $session): array
    {
        $stripeCustomerId = (string) ($session->customer ?? '');
        $email            = (string) ($session->customer_details->email ?? $session->customer_email ?? '');
        $name             = trim((string) ($session->customer_details->name ?? ''));
        $country          = $session->customer_details->address->country ?? null;

        if ($stripeCustomerId === '' || $email === '') {
            return ['ok' => false, 'message' => 'Session missing customer ID or email.'];
        }

        $sub = $session->subscription;
        if (! is_object($sub)) {
            return ['ok' => false, 'message' => 'Subscription not expanded on session.'];
        }

        $priceId = (string) ($sub->items->data[0]->price->id ?? '');
        $tier    = $priceId !== '' ? $this->products->tierForPrice($priceId) : null;

        if ($tier === null) {
            return ['ok' => false, 'message' => "No tier mapping for price {$priceId}."];
        }

        $customer = $this->customers->findOrCreate($email, $stripeCustomerId, $name ?: null, $country);

        $subscription = $this->subscriptions->upsert(
            $customer->id,
            (string) $sub->id,
            $priceId,
            (string) ($sub->status ?? ''),
            (bool) ($sub->cancel_at_period_end ?? false),
            self::tsToDate($sub->current_period_start ?? null),
            self::tsToDate($sub->current_period_end ?? null),
            self::tsToDate($sub->canceled_at ?? null),
        );

        $this->entitlements->grantMembershipFromSubscription(
            $customer->id,
            $tier,
            $subscription->id,
        );

        $this->wpSync->trigger($customer->id);
        $this->recordAffiliateConversion($session, $customer->id, $tier);

        return [
            'ok'           => true,
            'message'      => "Provisioned {$customer->email} → {$tier}",
            'customer_id'  => $customer->id,
            'tier'         => $tier,
            'redirect_url' => $this->buildSuccessUrl([
                'kind' => 'subscription',
                'tier' => $tier,
            ]),
        ];
    }

    private function handleGift(object $session): array
    {
        $meta = $session->metadata;
        if (($meta->checkout_type ?? '') !== 'gift') {
            return ['ok' => false, 'message' => 'Unknown payment checkout type.'];
        }

        $email        = (string) ($session->customer_details->email ?? $session->customer_email ?? '');
        $name         = trim((string) ($session->customer_details->name ?? ''));
        $country      = $session->customer_details->address->country ?? null;
        $tier         = (string) ($meta->tier ?? '');
        $quantity     = max(1, (int) ($meta->quantity ?? 1));
        $durationDays = max(1, (int) ($meta->duration_days ?? 365));

        if ($email === '' || $tier === '') {
            return ['ok' => false, 'message' => 'Gift session missing email or tier.'];
        }

        // Idempotency: handle() can be invoked from CheckoutController (browser
        // return), WebhookController (Stripe checkout.session.completed), and
        // ReconciliationController (cron sweep). All three race on the same
        // session_id. If gift codes already exist for this session, return the
        // success shape without re-minting (or re-emailing).
        $existing = $this->giftCodes->findByStripeSessionId((string) $session->id);
        if ($existing !== []) {
            $dashboardMode = (string) ($meta->dashboard_mode ?? '') === '1';
            $existingCustomerId = $existing[0]->purchasedBy;
            if ($dashboardMode) {
                $base    = rtrim($this->settings->getHomeUrl(), '/');
                return [
                    'ok'           => true,
                    'message'      => 'Gift codes already provisioned for this session (' . count($existing) . ').',
                    'customer_id'  => $existingCustomerId,
                    'tier'         => $tier,
                    'quantity'     => count($existing),
                    'redirect_url' => $base . '/my-gifts/',
                ];
            }
            return [
                'ok'           => true,
                'message'      => 'Gift codes already provisioned for this session (' . count($existing) . ').',
                'customer_id'  => $existingCustomerId,
                'tier'         => $tier,
                'quantity'     => count($existing),
                'redirect_url' => $this->buildSuccessUrl([
                    'kind' => 'gift',
                    'tier' => $tier,
                    'qty'  => (string) count($existing),
                ]),
            ];
        }

        $stripeCustomerId = (string) ($session->customer ?? '');
        $customer = $this->customers->findOrCreate(
            $email,
            $stripeCustomerId !== '' ? $stripeCustomerId : null,
            $name ?: null,
            $country,
        );

        // Look up pending recipients (set by CheckoutService when the buyer
        // chose direct-to-recipient mode). consume() reads + deletes in one
        // pass; missing rows return empty (legacy "buyer keeps codes" mode).
        $hasRecipientsFlag = (string) ($meta->has_recipients ?? '') === '1';
        $recipients = $hasRecipientsFlag
            ? $this->pendingRecipients->consume((string) $session->id)
            : [];

        $codes = $this->giftCodes->createBatch(
            $quantity,
            $customer->id,
            $tier,
            $durationDays,
            (string) $session->id,
            $recipients !== [] ? $recipients : null,
        );

        // Mailer dispatches both the buyer summary AND, where recipients are
        // attached to codes, individual personalized HTML emails.
        // Dashboard mode: buyer wants to manage these codes from /my-gifts/.
        // Skip the bulk-summary email and redirect them straight to the
        // dashboard instead of the welcome page.
        $dashboardMode = (string) ($meta->dashboard_mode ?? '') === '1';

        $this->mailer->sendGiftCodes($email, $name ?: 'Looth Member', $codes, $dashboardMode);
        $this->recordAffiliateConversion($session, $customer->id, $tier);

        if ($dashboardMode) {
            // /my-gifts/ is the dashboard slug seeded by the WP plugin.
            $base    = rtrim($this->settings->getHomeUrl(), '/');
            $dashUrl = $base . '/my-gifts/';

            return [
                'ok'           => true,
                'message'      => "Generated {$quantity} gift code(s) for {$email}; redirecting to dashboard.",
                'customer_id'  => $customer->id,
                'tier'         => $tier,
                'quantity'     => $quantity,
                'redirect_url' => $dashUrl,
            ];
        }

        return [
            'ok'           => true,
            'message'      => "Generated {$quantity} gift code(s) for {$email}",
            'customer_id'  => $customer->id,
            'tier'         => $tier,
            'quantity'     => $quantity,
            'redirect_url' => $this->buildSuccessUrl([
                'kind' => 'gift',
                'tier' => $tier,
                'qty'  => (string) $quantity,
            ]),
        ];
    }

    private function recordAffiliateConversion(object $session, int $customerId, string $tier): void
    {
        $ref = trim((string) ($session->metadata->affiliate_ref ?? ''));
        if ($ref === '') {
            return;
        }
        $stripeCustomerId = trim((string) ($session->customer ?? ''));
        $this->affiliates->recordConversion($ref, $customerId, $stripeCustomerId, (string) $session->id, $tier);
    }

    private function logVerification(
        int    $customerId,
        string $priceId,
        string $regionTag,
        string $billingCountry,
        string $issuerCountry,
        bool   $passed,
    ): void {
        try {
            $reason = "billing_country={$billingCountry} issuer_country={$issuerCountry} region_tag={$regionTag}";
            $err    = null;
            if (!$passed) {
                $err = "billing={$billingCountry} issuer={$issuerCountry} not both in {$regionTag}";
            }
            $this->auditLog->log(
                $customerId,
                'regional_verify',
                $priceId,
                $reason,
                $passed,
                $err,
            );
        } catch (\Throwable) {
            // Audit failure must never block the main flow.
        }
    }

    /**
     * Build the success-redirect URL with query params for the WP welcome page.
     * The page reads `kind` first to branch its messaging; the rest are
     * kind-specific extras (tier, qty, expires_at).
     */
    private function buildSuccessUrl(array $params): string
    {
        $base = $this->settings->getReturnSuccessUrl();
        $sep  = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . http_build_query(array_filter(
            $params,
            static fn ($v) => $v !== null && $v !== '',
        ));
    }

    private static function tsToDate(mixed $ts): ?DateTimeImmutable
    {
        if ($ts === null || $ts === '' || $ts === 0) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('U', (string) (int) $ts);
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }
}
