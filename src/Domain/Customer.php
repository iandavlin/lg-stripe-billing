<?php

declare(strict_types=1);

namespace LGSB\Domain;

readonly class Customer
{
    public function __construct(
        public int     $id,
        public string  $uuid,
        public ?string $stripeCustomerId,
        public string  $email,
        public ?string $name,
        public ?string $country,
        public ?string $locale,
        public ?string $blockedAt = null,
        public ?string $blockReason = null,
    ) {}

    public function isBlocked(): bool
    {
        return $this->blockedAt !== null;
    }
}
