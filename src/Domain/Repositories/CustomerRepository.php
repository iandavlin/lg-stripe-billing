<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

use LGSB\Domain\Customer;

interface CustomerRepository
{
    public function findById(int $id): ?Customer;

    public function findByUuid(string $uuid): ?Customer;

    public function findByEmail(string $email): ?Customer;

    /** Includes soft-deleted rows — used by findOrCreate to revive instead of duplicate-key. */
    public function findByEmailIncludingDeleted(string $email): ?Customer;

    public function undelete(int $id): void;

    public function findByStripeCustomerId(string $stripeCustomerId): ?Customer;

    public function create(
        string  $email,
        ?string $name,
        ?string $stripeCustomerId,
        ?string $country,
    ): Customer;

    public function updateStripeCustomerId(int $customerId, string $stripeCustomerId): void;
}
