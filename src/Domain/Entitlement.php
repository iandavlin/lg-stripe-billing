<?php

declare(strict_types=1);

namespace LGSB\Domain;

use DateTimeImmutable;

readonly class Entitlement
{
    public const KIND_MEMBERSHIP_TIER = 'membership_tier';
    public const KIND_EVENT_ACCESS    = 'event_access';
    public const KIND_DIGITAL_ACCESS  = 'digital_access';

    public const SOURCE_SUBSCRIPTION = 'subscription';
    public const SOURCE_ORDER        = 'order';
    public const SOURCE_MANUAL       = 'manual';
    public const SOURCE_COMP         = 'comp';

    public function __construct(
        public int                $id,
        public string             $uuid,
        public int                $customerId,
        public string             $kind,
        public string             $ref,
        public string             $sourceType,
        public ?int               $sourceId,
        public DateTimeImmutable  $startsAt,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt,
    ) {}

    public function isActive(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();
        if ($this->revokedAt !== null) {
            return false;
        }
        if ($this->expiresAt !== null && $this->expiresAt <= $now) {
            return false;
        }
        return $this->startsAt <= $now;
    }
}
