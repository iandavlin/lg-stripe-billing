<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Domain\Repositories\ProductRepository;

/**
 * Syncs product and price objects received via Stripe webhooks into the
 * local DB so the tier picker always reflects what's live in the Dashboard.
 *
 * Products: only `name` and `active` are synced — `ref` and `kind` are set
 * once manually via SQL and are never overwritten by webhook events.
 * Prices: optional metadata.region_tag, metadata.priority,
 * metadata.grants_duration_days are honoured.
 */
final class ProductSyncHandler
{
    public function __construct(private readonly ProductRepository $products) {}

    public function handleProductEvent(object $stripeProduct): void
    {
        $this->products->upsertProduct(
            (string) $stripeProduct->id,
            (string) $stripeProduct->name,
            'membership', // only used on first INSERT — updates skip kind/ref
            null,
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
