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
}
