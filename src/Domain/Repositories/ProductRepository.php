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
    ): void;

    /**
     * Return active membership products and their active prices,
     * ordered by product id then price priority.
     *
     * @return list<array{
     *     stripe_product_id: string,
     *     name: string,
     *     ref: string|null,
     *     prices: list<array{
     *         stripe_price_id: string,
     *         interval: string|null,
     *         unit_amount_cents: int,
     *         currency: string,
     *         region_tag: string|null,
     *     }>,
     * }>
     */
    public function listMembership(): array;
}
