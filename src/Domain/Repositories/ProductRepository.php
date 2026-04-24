<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

interface ProductRepository
{
    /**
     * Resolve the membership tier slug (e.g. 'looth2') for a Stripe price ID.
     * Null if the price isn't mapped to a membership product.
     */
    public function tierForPrice(string $stripePriceId): ?string;

    /**
     * Given a canonical (or any) price ID and an optional country, return
     * the best-matching price ID for that country based on region tags and
     * priority. May return the input unchanged.
     */
    public function resolvePriceForCountry(string $stripePriceId, ?string $countryCode): string;

    /**
     * Membership grant duration in days for a one-time price.
     * Null for indefinite (lifetime) or for recurring prices.
     */
    public function grantsDurationDays(string $stripePriceId): ?int;
}
