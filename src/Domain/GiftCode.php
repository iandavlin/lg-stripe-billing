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
        public ?DateTimeImmutable $voidedAt,
        public DateTimeImmutable  $createdAt,
        public ?string            $recipientEmail = null,
        public ?string            $recipientName  = null,
        public ?string            $giftMessage    = null,
        public ?DateTimeImmutable $emailSentAt    = null,
    ) {}

    public function hasRecipient(): bool
    {
        return $this->recipientEmail !== null && $this->recipientEmail !== '';
    }

    public function isRedeemed(): bool
    {
        return $this->redeemedAt !== null;
    }

    public function isVoided(): bool
    {
        return $this->voidedAt !== null;
    }
}
