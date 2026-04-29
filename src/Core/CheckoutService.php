<?php

declare(strict_types=1);

namespace LGSB\Core;

use InvalidArgumentException;
use LGSB\Contracts\SettingsStore;
use LGSB\Domain\Repositories\ProductRepository;
use LGSB\Stripe\StripeGateway;
use RuntimeException;

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
     * Create a one-time gift/bulk Checkout Session.
     * Quantity >= 2 triggers this path. Discounts are applied per BulkPricer
     * (BULK_DISCOUNT_TIERS env). The return handler generates one gift code per seat.
     *
     * @return array{clientSecret:string}
     */
    public function createGiftCheckoutSession(
        string  $priceId,
        int     $quantity,
        ?string $email   = null,
        ?string $country = null,
    ): array {
        if ($quantity < 2) {
            throw new InvalidArgumentException('Gift checkout requires quantity >= 2.');
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
                    'currency'    => $priceData['currency'],
                    'unit_amount' => $unitCents,
                    'product'     => $priceData['stripe_product_id'],
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
