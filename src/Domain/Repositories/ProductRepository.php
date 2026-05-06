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
     *
     * With product-level region_tag, regional routing is done by selecting a
     * different product (via regionTagForPrice) rather than a different price
     * on the same product. This method is kept for standard subscriptions and
     * simply returns the input price unchanged.
     */
    public function resolvePriceForCountry(string $stripePriceId, ?string $countryCode): string;

    /**
     * Membership grant duration in days for a one-time price.
     * Null for indefinite (lifetime) or for recurring prices.
     */
    public function grantsDurationDays(string $stripePriceId): ?int;

    /**
     * Annual base price in cents for a membership tier (e.g. 'looth2' → 6000).
     * Used for cross-tier proration. Uses the standard (region_tag IS NULL)
     * product's yearly price.
     * Null if the tier has no membership product or no yearly price.
     */
    public function pricePerYearCentsForTier(string $tier): ?int;

    /**
     * Raw price data needed for gift/bulk checkout: amount, currency, interval,
     * and optional duration. Also returns the owning product's region_tag so
     * the controller can route regional prices to the setup flow.
     * Null if the price isn't in our DB.
     *
     * @return array{unit_amount_cents:int,currency:string,interval:string|null,grants_duration_days:int|null,product_name:string,product_region_tag:string|null}|null
     */
    public function findPriceData(string $stripePriceId): ?array;

    /**
     * Upsert a product from a Stripe product.* webhook event.
     * ref is preserved from the DB if the incoming value is null.
     */
    public function upsertProduct(
        string  $stripeProductId,
        string  $name,
        string  $kind,
        ?string $ref,
        bool    $active,
    ): void;

    /**
     * Upsert a price from a Stripe price.* webhook event.
     * Silently ignored if the parent product is not yet in the DB.
     */
    public function upsertPrice(
        string  $stripePriceId,
        string  $stripeProductId,
        string  $type,
        ?string $interval,
        int     $unitAmountCents,
        string  $currency,
        ?string $regionTag,
        int     $priority,
        bool    $active,
        ?int    $grantsDurationDays,
        float   $discountScale = 1.0,
        int     $trialDays = 0,
    ): void;

    /**
     * Return active membership products and their active prices.
     *
     * Country-aware: standard products (region_tag IS NULL) are always included.
     * If $countryCode is mapped to a region_tag in price_regions, only the
     * regional product for that tag is returned for each tier — it replaces the
     * standard product in the listing. This means the caller always gets exactly
     * one product per tier (either standard or regional, never both).
     *
     * @return list<array{
     *     stripe_product_id: string,
     *     name: string,
     *     ref: string|null,
     *     region_tag: string|null,
     *     prices: list<array{
     *         stripe_price_id: string,
     *         type: string,
     *         interval: string|null,
     *         unit_amount_cents: int,
     *         currency: string,
     *         region_tag: string|null,
     *         grants_duration_days: int|null,
     *     }>,
     * }>
     */
    public function listMembership(?string $countryCode = null): array;

    /**
     * The region_tag of the product this price belongs to.
     * Null means the price is on a standard (non-regional) product.
     */
    public function regionTagForPrice(string $stripePriceId): ?string;

    /**
     * Returns true if the given country code is mapped to the given region_tag
     * in price_regions. Used to verify billing-country eligibility.
     */
    public function countryInRegion(string $countryCode, string $regionTag): bool;

    /**
     * Find the standard-tier (region_tag IS NULL) price for the same tier ref
     * and interval as the given regional price. Used on verification failure to
     * offer the customer an upgrade path to standard pricing.
     * Returns null if no standard price is found.
     */
    public function standardPriceForTierAndInterval(string $regionalPriceId): ?string;
}
