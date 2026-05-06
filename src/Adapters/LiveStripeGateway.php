<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Stripe\StripeGateway;
use Stripe\StripeClient;

final class LiveStripeGateway implements StripeGateway
{
    /**
     * Pinned Stripe API version. Locks request/response shapes to a known
     * good version so the integration is insulated from Dashboard-side
     * version flips. Bump intentionally after testing — see Stripe API
     * changelog at https://docs.stripe.com/upgrades.
     */
    private const STRIPE_API_VERSION = '2025-03-31.basil';

    private readonly StripeClient $stripe;

    public function __construct(string $secretKey)
    {
        $this->stripe = new StripeClient([
            'api_key'        => $secretKey,
            'stripe_version' => self::STRIPE_API_VERSION,
        ]);
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

    public function constructWebhookEvent(string $payload, string $sigHeader, string $secret): object
    {
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
    }

    public function findPromotionCodeId(string $code): ?string
    {
        $resp = $this->stripe->promotionCodes->all([
            'code'   => $code,
            'active' => true,
            'limit'  => 1,
        ]);
        $data = $resp->data ?? [];
        if ($data === []) {
            return null;
        }
        return (string) $data[0]->id;
    }

    public function createCustomer(string $email, ?string $name = null): object
    {
        $params = ['email' => $email];
        if ($name !== null && $name !== '') {
            $params['name'] = $name;
        }
        return $this->stripe->customers->create($params);
    }

    public function retrieveSetupIntent(string $setupIntentId, array $expand = []): object
    {
        $params = $expand !== [] ? ['expand' => $expand] : [];
        return $this->stripe->setupIntents->retrieve($setupIntentId, $params);
    }

    public function retrievePaymentMethod(string $paymentMethodId): object
    {
        return $this->stripe->paymentMethods->retrieve($paymentMethodId);
    }

    public function createSubscription(
        string $stripeCustomerId,
        string $priceId,
        string $paymentMethodId,
    ): object {
        return $this->stripe->subscriptions->create([
            'customer'               => $stripeCustomerId,
            'items'                  => [['price' => $priceId]],
            'default_payment_method' => $paymentMethodId,
        ]);
    }

    public function detachPaymentMethod(string $paymentMethodId): void
    {
        $this->stripe->paymentMethods->detach($paymentMethodId);
    }

    public function updateSubscription(string $subscriptionId, array $params): object
    {
        return $this->stripe->subscriptions->update($subscriptionId, $params);
    }
}
