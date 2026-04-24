<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Domain\Customer;
use LGSB\Domain\Repositories\CustomerRepository;

class CustomerManager
{
    public function __construct(
        private readonly CustomerRepository $customers,
    ) {}

    /**
     * Find an existing customer by Stripe ID or email, or create one.
     *
     * Lookup priority: stripe_customer_id > email. When an email match is found
     * and we now have a Stripe ID for the first time, the record is upgraded.
     */
    public function findOrCreate(
        string  $email,
        ?string $stripeCustomerId = null,
        ?string $name = null,
        ?string $country = null,
    ): Customer {
        if ($stripeCustomerId !== null) {
            $byStripe = $this->customers->findByStripeCustomerId($stripeCustomerId);
            if ($byStripe !== null) {
                return $byStripe;
            }
        }

        $byEmail = $this->customers->findByEmail($email);
        if ($byEmail !== null) {
            if ($stripeCustomerId !== null && $byEmail->stripeCustomerId === null) {
                $this->customers->updateStripeCustomerId($byEmail->id, $stripeCustomerId);
                return $this->customers->findById($byEmail->id) ?? $byEmail;
            }
            return $byEmail;
        }

        return $this->customers->create($email, $name, $stripeCustomerId, $country);
    }
}
