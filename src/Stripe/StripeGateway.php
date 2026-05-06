<?php

declare(strict_types=1);

namespace LGSB\Stripe;

/**
 * Thin typed facade over \Stripe\StripeClient.
 *
 * Only the methods we actually use. Keeps Core services unit-testable
 * without mocking the full Stripe SDK surface.
 */
interface StripeGateway
{
    public function createCheckoutSession(array $params): object;

    public function retrieveCheckoutSession(string $sessionId, array $expand = []): object;

    public function retrieveSubscription(string $subscriptionId, array $expand = []): object;

    /** @return iterable<object> */
    public function listCustomerSubscriptions(string $stripeCustomerId, array $params = []): iterable;

    public function createPortalSession(string $stripeCustomerId, string $returnUrl): object;

    /**
     * Resolve a customer-facing promotion code string (e.g. "PATREON5") to a
     * Stripe promotion_code ID (e.g. "promo_xxx"). Null if not found / inactive.
     */
    public function findPromotionCodeId(string $code): ?string;

    /**
     * Verify and decode a Stripe webhook payload.
     *
     * @throws \Stripe\Exception\SignatureVerificationException on bad signature
     */
    public function constructWebhookEvent(string $payload, string $sigHeader, string $secret): object;

    /**
     * Create a Stripe customer. Used to pre-create before setup-mode Checkout
     * sessions so session.customer is guaranteed to be populated.
     */
    public function createCustomer(string $email, ?string $name = null): object;

    /** Retrieve a Setup Intent, optionally expanding nested fields. */
    public function retrieveSetupIntent(string $setupIntentId, array $expand = []): object;

    /** Retrieve a PaymentMethod object. */
    public function retrievePaymentMethod(string $paymentMethodId): object;

    /**
     * Create a subscription for an existing customer, charging the given
     * payment method immediately on the first billing cycle.
     */
    public function createSubscription(
        string $stripeCustomerId,
        string $priceId,
        string $paymentMethodId,
    ): object;

    /**
     * Detach a payment method from its customer. The PM stays in Stripe's
     * vault but can no longer be charged. Used when a regional billing-country
     * check fails — no charge was ever made.
     */
    public function detachPaymentMethod(string $paymentMethodId): void;

    public function updateSubscription(string $subscriptionId, array $params): object;
}
