<?php

declare(strict_types=1);

namespace LGSB\Core;

use InvalidArgumentException;
use LGSB\Contracts\SettingsStore;
use LGSB\Domain\Repositories\PendingGiftRecipientsRepository;
use LGSB\Domain\Repositories\ProductRepository;
use LGSB\Stripe\StripeGateway;
use RuntimeException;

class CheckoutService
{
    public function __construct(
        private readonly SettingsStore                   $settings,
        private readonly StripeGateway                   $stripe,
        private readonly ProductRepository               $products,
        private readonly CustomerManager                 $customers,
        private readonly PendingGiftRecipientsRepository $pendingRecipients,
    ) {}

    /**
     * Create an embedded-mode Checkout Session for a membership subscription.
     *
     * @return array{clientSecret:string}
     */
    public function createSubscriptionSession(
        string  $priceId,
        ?string $email     = null,
        ?string $country   = null,
        ?string $promoCode = null,
        ?string $name      = null,
    ): array {
        if ($this->products->tierForPrice($priceId) === null) {
            throw new InvalidArgumentException("Price {$priceId} is not mapped to a membership tier.");
        }

        $resolvedPriceId = $this->products->resolvePriceForCountry($priceId, $country);

        $params = [
            'ui_mode'    => 'embedded',
            'mode'       => 'subscription',
            'line_items' => [['price' => $resolvedPriceId, 'quantity' => 1]],
            'return_url' => $this->settings->getCheckoutReturnUrl(),
        ];

        $this->applyPromoOrAllow($params, $promoCode);
        $this->attachCustomer($params, $email, $country, $name);

        $session = $this->stripe->createCheckoutSession($params);
        return ['clientSecret' => (string) $session->client_secret];
    }

    /**
     * Create an embedded-mode Checkout Session for a one-time membership
     * purchase (e.g. "Pay $66 for a year of Looth LITE — no auto-renew").
     *
     * @return array{clientSecret:string}
     */
    public function createOneTimeMembershipSession(
        string  $priceId,
        ?string $email     = null,
        ?string $country   = null,
        ?string $promoCode = null,
        ?string $name      = null,
    ): array {
        $tier = $this->products->tierForPrice($priceId);
        if ($tier === null) {
            throw new InvalidArgumentException("Price {$priceId} is not mapped to a membership tier.");
        }

        $priceData = $this->products->findPriceData($priceId);
        if ($priceData === null) {
            throw new InvalidArgumentException("Price {$priceId} not found.");
        }
        if ($priceData['interval'] !== null) {
            throw new InvalidArgumentException("Price {$priceId} is recurring; use createSubscriptionSession.");
        }
        $durationDays = $priceData['grants_duration_days'] ?? 365;

        $resolvedPriceId = $this->products->resolvePriceForCountry($priceId, $country);

        $params = [
            'ui_mode'    => 'embedded',
            'mode'       => 'payment',
            'line_items' => [['price' => $resolvedPriceId, 'quantity' => 1]],
            'return_url' => $this->settings->getCheckoutReturnUrl(),
            'metadata'   => [
                'checkout_type' => 'membership_annual',
                'tier'          => $tier,
                'price_id'      => $priceId,
                'duration_days' => (string) $durationDays,
            ],
        ];

        $this->applyPromoOrAllow($params, $promoCode);
        $this->attachCustomer($params, $email, $country, $name);

        $session = $this->stripe->createCheckoutSession($params);
        return ['clientSecret' => (string) $session->client_secret];
    }

    /**
     * If a promo code string is supplied, resolve it to a Stripe promotion_code
     * ID and apply via `discounts`. Otherwise enable `allow_promotion_codes` so
     * the customer can enter one on the Stripe Checkout page.
     *
     * Unknown / inactive codes silently fall back to allow_promotion_codes — we
     * don't want a stale link to break checkout entirely.
     */
    private function applyPromoOrAllow(array &$params, ?string $promoCode): void
    {
        if ($promoCode === null || $promoCode === '') {
            $params['allow_promotion_codes'] = true;
            return;
        }
        $promoId = $this->stripe->findPromotionCodeId($promoCode);
        if ($promoId === null) {
            $params['allow_promotion_codes'] = true;
            return;
        }
        // Stripe disallows mixing `discounts` with `allow_promotion_codes`.
        $params['discounts'] = [['promotion_code' => $promoId]];
    }

    private function attachCustomer(array &$params, ?string $email, ?string $country, ?string $name = null): void
    {
        if ($email === null || $email === '') {
            return;
        }
        $customer = $this->customers->findOrCreate($email, null, $name, $country);
        if ($customer->stripeCustomerId !== null) {
            $params['customer'] = $customer->stripeCustomerId;
        } else {
            $params['customer_email'] = $email;
        }
    }

    /**
     * Create a one-time gift/bulk Checkout Session.
     * Quantity >= 2 triggers this path. Discounts are applied per BulkPricer
     * (BULK_DISCOUNT_TIERS env). The return handler generates one gift code per seat.
     *
     * If $recipients is provided, the buyer wants direct-to-recipient emails:
     * each entry pairs with one generated code (by position). Stored
     * separately and consumed on /v1/return — Stripe metadata can't reliably
     * carry a 50-recipient batch.
     *
     * @param list<array{email?:?string, name?:?string, message?:?string}>|null $recipients
     * @return array{clientSecret:string}
     */
    public function createGiftCheckoutSession(
        string  $priceId,
        int     $quantity,
        ?string $email      = null,
        ?string $country    = null,
        ?string $promoCode  = null,
        ?string $name       = null,
        ?array  $recipients = null,
    ): array {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Gift checkout requires quantity >= 1.');
        }

        $tier = $this->products->tierForPrice($priceId);
        if ($tier === null) {
            throw new InvalidArgumentException("Price {$priceId} is not mapped to a membership tier.");
        }

        $priceData = $this->products->findPriceData($priceId);
        if ($priceData === null) {
            throw new InvalidArgumentException("Price {$priceId} not found.");
        }

        $pricer       = BulkPricer::fromEnvString(implode(',', array_map(
            static fn (array $t): string => "{$t['min']}:{$t['pct']}",
            $this->settings->getBulkDiscountTiers(),
        )));
        $unitCents    = $pricer->discountedUnitAmountCents($priceData['unit_amount_cents'], $quantity);
        $pct          = $pricer->discountPct($quantity);
        $durationDays = $priceData['grants_duration_days'] ?? match ($priceData['interval']) {
            'year'  => 365,
            'month' => 30,
            default => 365,
        };

        $params = [
            'ui_mode'    => 'embedded',
            'mode'       => 'payment',
            'line_items' => [[
                'quantity'   => $quantity,
                'price_data' => [
                    'currency'     => $priceData['currency'],
                    'unit_amount'  => $unitCents,
                    'product_data' => [
                        'name' => $quantity === 1
                            ? "{$priceData['product_name']} — Gift Membership"
                            : "{$priceData['product_name']} — {$quantity}-Seat Gift Pack",
                    ],
                ],
            ]],
            'return_url' => $this->settings->getCheckoutReturnUrl(),
            'metadata'   => [
                'checkout_type' => 'gift',
                'tier'          => $tier,
                'quantity'      => (string) $quantity,
                'price_id'      => $priceId,
                'duration_days' => (string) $durationDays,
            ],
        ];

        $this->applyPromoOrAllow($params, $promoCode);
        $this->attachCustomer($params, $email, $country, $name);

        // Flag the session so handleGift() knows to look up pending recipients.
        // Cheaper than a DB hit on every gift return when no recipients exist.
        if ($recipients !== null && $recipients !== []) {
            $params['metadata']['has_recipients'] = '1';
        }

        $session = $this->stripe->createCheckoutSession($params);

        // Persist the recipient list keyed by the just-minted Stripe session ID
        // so handleGift() on /v1/return can pair them with the generated codes.
        if ($recipients !== null && $recipients !== []) {
            $this->pendingRecipients->store((string) $session->id, $recipients);
        }

        return ['clientSecret' => (string) $session->client_secret];
    }

    /**
     * Create an embedded-mode Checkout Session in setup mode for a regional
     * membership price. No charge is made — the customer's card is saved as a
     * Setup Intent so we can verify the billing country before subscribing.
     *
     * On return, ReturnHandler::handleRegionalVerify() checks the billing
     * country against price_regions for the price's region_tag. Pass →
     * subscription created; fail → payment method detached, redirect to the
     * regional fail page.
     *
     * Promo codes are not applicable in setup mode (no payment to discount).
     *
     * @return array{clientSecret:string}
     */
    public function createRegionalSetupSession(
        string  $priceId,
        ?string $email   = null,
        ?string $country = null,
        ?string $name    = null,
    ): array {
        $tier = $this->products->tierForPrice($priceId);
        if ($tier === null) {
            throw new InvalidArgumentException("Price {$priceId} is not mapped to a membership tier.");
        }

        $regionTag = $this->products->regionTagForPrice($priceId);
        if ($regionTag === null) {
            throw new InvalidArgumentException("Price {$priceId} is not a regional price; use createSubscriptionSession.");
        }

        $priceData = $this->products->findPriceData($priceId);
        if ($priceData === null) {
            throw new InvalidArgumentException("Price {$priceId} not found.");
        }

        // Setup mode does NOT auto-create a Stripe customer from customer_email
        // (only subscription/payment modes do). Without a customer, session.customer
        // is empty after completion and we can't attach the saved PM to anything.
        // Pre-create the Stripe customer (or reuse the linked one) so session.customer
        // is guaranteed populated when ReturnHandler::handleRegionalVerify() runs.
        if ($email === null || $email === '') {
            throw new InvalidArgumentException('Email is required for regional setup checkout.');
        }

        $customer = $this->customers->findOrCreate($email, null, $name, $country);
        if ($customer->stripeCustomerId === null) {
            $stripeCust = $this->stripe->createCustomer($email, $name);
            $customer   = $this->customers->findOrCreate($email, (string) $stripeCust->id, $name, $country);
        }

        $params = [
            'ui_mode'    => 'embedded',
            'mode'       => 'setup',
            'currency'   => $priceData['currency'],
            'customer'   => $customer->stripeCustomerId,
            'return_url' => $this->settings->getCheckoutReturnUrl(),
            'metadata'   => [
                'checkout_type' => 'regional_verify',
                'region_tag'    => $regionTag,
                'price_id'      => $priceId,
                'tier'          => $tier,
            ],
        ];

        $session = $this->stripe->createCheckoutSession($params);
        return ['clientSecret' => (string) $session->client_secret];
    }

    /**
     * Create a Customer Portal session for an existing customer.
     *
     * @return array{url:string}
     */
    public function createPortalSession(int $customerId): array
    {
        $customer = $this->customers->findById($customerId);
        if ($customer === null || $customer->stripeCustomerId === null) {
            throw new InvalidArgumentException("Customer {$customerId} has no Stripe ID.");
        }
        $session = $this->stripe->createPortalSession(
            $customer->stripeCustomerId,
            $this->settings->getHomeUrl(),
        );
        return ['url' => (string) $session->url];
    }
}
