<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Stripe\StripeGateway;
use Stripe\StripeClient;

final class LiveStripeGateway implements StripeGateway
{
    private readonly StripeClient $stripe;

    public function __construct(string $secretKey)
    {
        $this->stripe = new StripeClient($secretKey);
    }

    public function createCheckoutSession(array $params): object
    {
        return $this->stripe->checkout->sessions->create($params);
    }

    public function retrieveCheckoutSession(string $sessionId, array $expand = []): object
    {
        $params = $expand !== [] ? ['expand' => $expand] : [];
        return $this->stripe->checkout->sessions->retrieve($sessionId, $params);
    }

    public function retrieveSubscription(string $subscriptionId, array $expand = []): object
    {
        $params = $expand !== [] ? ['expand' => $expand] : [];
        return $this->stripe->subscriptions->retrieve($subscriptionId, $params);
    }

    public function listCustomerSubscriptions(string $stripeCustomerId, array $params = []): iterable
    {
        return $this->stripe->subscriptions->all(array_merge(
            ['customer' => $stripeCustomerId, 'status' => 'all', 'limit' => 100],
            $params,
        ));
    }

    public function createPortalSession(string $stripeCustomerId, string $returnUrl): object
    {
        return $this->stripe->billingPortal->sessions->create([
            'customer'   => $stripeCustomerId,
            'return_url' => $returnUrl,
        ]);
    }
}
