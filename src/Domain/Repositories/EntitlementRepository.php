<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

use DateTimeImmutable;
use LGSB\Domain\Entitlement;

interface EntitlementRepository
{
    public function findById(int $id): ?Entitlement;

    /** @return Entitlement[] */
    public function activeForCustomer(int $customerId, ?DateTimeImmutable $now = null): array;

    /**
     * Active entitlements (any starts_at, expires_at > now, not revoked) sourced
     * from gift codes. Used by GiftRedemptionService to compute conflicts.
     *
     * @return Entitlement[]
     */
    public function activeGiftsForCustomer(int $customerId, ?DateTimeImmutable $now = null): array;

    /** @return Entitlement[] */
    public function findBySource(string $sourceType, int $sourceId): array;

    public function grant(
        int                $customerId,
        string             $kind,
        string             $ref,
        string             $sourceType,
        ?int               $sourceId,
        ?DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $startsAt = null,
        ?array             $metadata = null,
    ): Entitlement;

    public function revoke(int $entitlementId): void;

    public function revokeBySource(string $sourceType, int $sourceId): void;
}
