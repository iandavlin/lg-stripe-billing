<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Domain\Repositories\ProductRepository;

/**
 * Syncs product and price objects received via Stripe webhooks into the
 * local DB so the tier picker always reflects what's live in the Dashboard.
 *
 * Convention: set metadata.ref = 'looth2' (tier slug) and
 * metadata.kind = 'membership' on Stripe products. metadata.region_tag,
 * metadata.priority, and metadata.grants_duration_days are optional on prices.
 */
final class ProductSyncHandler
{
    public function __construct(private readonly ProductRepository $products) {}

    public function handleProductEvent(object $stripeProduct): void
    {
        $meta = $stripeProduct->metadata ?? null;
        $ref  = ($meta->ref  ?? null) ?: null;
        $kind = ($meta->kind ?? null) ?: 'membership';

        $this->products->upsertProduct(
            (string) $stripeProduct->id,
            (string) $stripeProduct->name,
            $kind,
            $ref,
            (bool) $stripeProduct->active,
        );
    }

    public function handlePriceEvent(object $stripePrice): void
    {
        $meta       = $stripePrice->metadata ?? null;
        $regionTag  = ($meta->region_tag ?? null) ?: null;
        $priority   = isset($meta->priority)             ? (int) $meta->priority             : 100;
        $grantsDays = isset($meta->grants_duration_days) ? (int) $meta->grants_duration_days : null;

        $interval = $stripePrice->type === 'recurring'
            ? (($stripePrice->recurring->interval ?? null) ?: null)
            : null;

        $this->products->upsertPrice(
            (string) $stripePrice->id,
            (string) $stripePrice->product,
            (string) $stripePrice->type,
            $interval,
            (int) ($stripePrice->unit_amount ?? 0),
            strtolower((string) $stripePrice->currency),
            $regionTag,
            $priority,
            (bool) $stripePrice->active,
            $grantsDays,
        );
    }
}
