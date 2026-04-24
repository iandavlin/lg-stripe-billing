<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

use DateTimeImmutable;
use LGSB\Domain\Subscription;

interface SubscriptionRepository
{
    public function findById(int $id): ?Subscription;

    public function findByStripeId(string $stripeSubscriptionId): ?Subscription;

    /** @return Subscription[] */
    public function findActiveForCustomer(int $customerId): array;

    public function upsert(
        int                $customerId,
        string             $stripeSubscriptionId,
        string             $stripePriceId,
        string             $status,
        bool               $cancelAtPeriodEnd,
        ?DateTimeImmutable $currentPeriodStart,
        ?DateTimeImmutable $currentPeriodEnd,
        ?DateTimeImmutable $canceledAt,
    ): Subscription;
}
