<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

interface AffiliateRepository
{
    /** Find an affiliate by slug. Returns ['id', 'slug', 'label', 'created_at'] or null. */
    public function findBySlug(string $slug): ?array;

    /** List all affiliates with click and conversion counts. */
    public function listWithCounts(): array;

    /** Create a new affiliate. Returns the new row. */
    public function create(string $slug, string $label): array;

    /**
     * Record a click for the given affiliate slug.
     * Silently no-ops if the slug doesn't exist.
     */
    public function recordClick(string $slug): void;

    /**
     * Record a conversion for the given affiliate slug.
     * Silently no-ops if the slug doesn't exist or the session was already recorded.
     */
    public function recordConversion(string $slug, int $customerId, string $stripeSessionId, string $tier): void;

    /** Return recent conversions for one affiliate (newest first). */
    public function conversionsForAffiliate(int $affiliateId, int $limit = 50): array;
}
