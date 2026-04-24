<?php

declare(strict_types=1);

namespace LGSB\Core;

use InvalidArgumentException;
use LGSB\Contracts\SettingsStore;
use LGSB\Domain\Repositories\ProductRepository;
use LGSB\Stripe\StripeGateway;

class CheckoutService
{
    public function __construct(
        private readonly SettingsStore     $settings,
        private readonly StripeGateway     $stripe,
        private readonly ProductRepository $products,
        private readonly CustomerManager   $customers,
    ) {}

    /**
     * Create an embedded-mode Checkout Session for a membership subscription.
     *
     * @return array{clientSecret:string}
     */
    public function createSubscriptionSession(
        string  $priceId,
        ?string $email = null,
        ?string $country = null,
    ): array {
        if ($this->products->tierForPrice($priceId) === null) {
            throw new InvalidArgumentException("Price {$priceId} is not mapped to a membership tier.");
        }

        $resolvedPriceId = $this->products->resolvePriceForCountry($priceId, $country);

        $params = [
            'ui_mode'               => 'embedded',
            'mode'                  => 'subscription',
            'line_items'            => [['price' => $resolvedPriceId, 'quantity' => 1]],
            'return_url'            => $this->settings->getCheckoutReturnUrl(),
            'allow_promotion_codes' => true,
        ];

        if ($email !== null && $email !== '') {
            $customer = $this->customers->findOrCreate($email, null, null, $country);
            if ($customer->stripeCustomerId !== null) {
                $params['customer'] = $customer->stripeCustomerId;
            } else {
                $params['customer_email'] = $email;
            }
        }

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
