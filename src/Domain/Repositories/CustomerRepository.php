<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

use LGSB\Domain\Customer;

interface CustomerRepository
{
    public function findById(int $id): ?Customer;

    public function findByUuid(string $uuid): ?Customer;

    public function findByEmail(string $email): ?Customer;

    public function findByStripeCustomerId(string $stripeCustomerId): ?Customer;

    public function create(
        string  $email,
        ?string $name,
        ?string $stripeCustomerId,
        ?string $country,
    ): Customer;

    public function updateStripeCustomerId(int $customerId, string $stripeCustomerId): void;
}
