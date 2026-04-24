<?php

declare(strict_types=1);

namespace LGSB\Core;

use DateTimeImmutable;
use LGSB\Domain\Entitlement;
use LGSB\Domain\Repositories\EntitlementRepository;

class EntitlementManager
{
    public function __construct(
        private readonly EntitlementRepository $entitlements,
    ) {}

    /**
     * Grant a membership tier entitlement backed by a subscription.
     * Any existing entitlement sourced from the same subscription is revoked first
     * so tier changes within one subscription produce a clean audit trail.
     */
    public function grantMembershipFromSubscription(
        int    $customerId,
        string $tierRef,
        int    $subscriptionId,
    ): Entitlement {
        $this->entitlements->revokeBySource(Entitlement::SOURCE_SUBSCRIPTION, $subscriptionId);
        return $this->entitlements->grant(
            $customerId,
            Entitlement::KIND_MEMBERSHIP_TIER,
            $tierRef,
            Entitlement::SOURCE_SUBSCRIPTION,
            $subscriptionId,
            null,
        );
    }

    /**
     * Grant a membership tier entitlement backed by a one-time order.
     * $expiresAt is null for lifetime memberships, otherwise the computed end date.
     */
    public function grantMembershipFromOrder(
        int                $customerId,
        string             $tierRef,
        int                $orderId,
        ?DateTimeImmutable $expiresAt,
    ): Entitlement {
        return $this->entitlements->grant(
            $customerId,
            Entitlement::KIND_MEMBERSHIP_TIER,
            $tierRef,
            Entitlement::SOURCE_ORDER,
            $orderId,
            $expiresAt,
        );
    }

    public function revokeForSubscription(int $subscriptionId): void
    {
        $this->entitlements->revokeBySource(Entitlement::SOURCE_SUBSCRIPTION, $subscriptionId);
    }

    /**
     * Return the current active membership tier ref (e.g. 'looth2'), or null.
     * If multiple active tiers exist (shouldn't in practice) the highest-sorted wins;
     * looth1..looth4 string-compare in the right order.
     */
    public function activeTier(int $customerId, ?DateTimeImmutable $now = null): ?string
    {
        $active = $this->entitlements->activeForCustomer($customerId, $now);
        $tiers  = array_values(array_filter(
            $active,
            static fn (Entitlement $e): bool => $e->kind === Entitlement::KIND_MEMBERSHIP_TIER,
        ));
        if ($tiers === []) {
            return null;
        }
        usort($tiers, static fn (Entitlement $a, Entitlement $b): int => strcmp($b->ref, $a->ref));
        return $tiers[0]->ref;
    }
}
