<?php

declare(strict_types=1);

namespace LGSB\Domain;

use DateTimeImmutable;

readonly class Subscription
{
    public const ACTIVE_STATUSES = ['active', 'trialing', 'past_due'];

    public function __construct(
        public int                $id,
        public int                $customerId,
        public string             $stripeSubscriptionId,
        public string             $stripePriceId,
        public string             $status,
        public bool               $cancelAtPeriodEnd,
        public ?DateTimeImmutable $currentPeriodStart,
        public ?DateTimeImmutable $currentPeriodEnd,
        public ?DateTimeImmutable $canceledAt,
    ) {}

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
