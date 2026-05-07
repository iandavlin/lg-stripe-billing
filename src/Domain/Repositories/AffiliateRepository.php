<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

interface AffiliateRepository
{
    /** Find an affiliate by slug. Returns row array or null. */
    public function findBySlug(string $slug): ?array;

    /** List all affiliates with click/conversion counts and estimated payouts. */
    public function listWithCounts(): array;

    /** Create a new affiliate. Returns the new row. */
    public function create(string $slug, string $label): array;

    /** Update commission rates for an affiliate. */
    public function updateCommission(int $id, float $commissionPct, float $commissionPctAnnual, float $retentionBonusPct): void;

    /** Find affiliate linked to a WP user ID, with counts. Returns null if none. */
    public function findByWpUserId(int $wpUserId): ?array;

    /**
     * Record a click for the given affiliate slug.
     * Silently no-ops if the slug doesn't exist.
     */
    public function recordClick(string $slug): void;

    /**
     * Record a conversion for the given affiliate slug.
     * Silently no-ops if the slug doesn't exist or the session was already recorded.
     */
    public function recordConversion(string $slug, int $customerId, string $stripeCustomerId, string $stripeSessionId, string $tier): void;

    /** Return recent conversions for one affiliate (newest first). */
    public function conversionsForAffiliate(int $affiliateId, int $limit = 50): array;

    /**
     * Return conversions that hit the 1-year mark and haven't been flagged yet.
     * Used by the retention poller to identify bonus-eligible customers.
     */
    public function retentionCandidates(): array;

    /** Mark a conversion as retention-bonus eligible. */
    public function markRetentionEligible(int $conversionId): void;
}
