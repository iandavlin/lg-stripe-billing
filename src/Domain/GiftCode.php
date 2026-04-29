<?php

declare(strict_types=1);

namespace LGSB\Domain;

use DateTimeImmutable;

readonly class GiftCode
{
    public function __construct(
        public int                $id,
        public string             $code,
        public string             $tier,
        public int                $durationDays,
        public int                $purchasedBy,
        public ?int               $redeemedBy,
        public ?string            $stripeSessionId,
        public ?DateTimeImmutable $redeemedAt,
        public DateTimeImmutable  $createdAt,
    ) {}

    public function isRedeemed(): bool
    {
        return $this->redeemedAt !== null;
    }
}
