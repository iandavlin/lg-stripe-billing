<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Domain\Repositories\ProductRepository;
use PDO;

final class PdoProductRepository implements ProductRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function tierForPrice(string $stripePriceId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.ref
             FROM prices pr
             JOIN products p ON p.id = pr.product_id
             WHERE pr.stripe_price_id = ?
               AND p.kind = 'membership'
               AND p.active = 1
             LIMIT 1"
        );
        $stmt->execute([$stripePriceId]);
        $ref = $stmt->fetchColumn();
        return $ref !== false && $ref !== null ? (string) $ref : null;
    }

    public function resolvePriceForCountry(string $stripePriceId, ?string $countryCode): string
    {
        // Find the product the requested price belongs to.
        $stmt = $this->pdo->prepare(
            'SELECT product_id FROM prices WHERE stripe_price_id = ? LIMIT 1'
        );
        $stmt->execute([$stripePriceId]);
        $productId = $stmt->fetchColumn();
        if ($productId === false) {
            return $stripePriceId;
        }

        // Pick the best-matching active price for that product + country.
        // priority lower-wins; default-region (region_tag IS NULL) is fallback.
        $stmt = $this->pdo->prepare(
            'SELECT pr.stripe_price_id
             FROM prices pr
             LEFT JOIN price_regions r
                ON r.region_tag = pr.region_tag AND r.country_code = ?
             WHERE pr.product_id = ?
               AND pr.active = 1
               AND (pr.region_tag IS NULL OR r.country_code IS NOT NULL)
             ORDER BY pr.priority ASC
             LIMIT 1'
        );
        $stmt->execute([$countryCode ?? '', $productId]);
        $resolved = $stmt->fetchColumn();
        return $resolved !== false ? (string) $resolved : $stripePriceId;
    }

    public function grantsDurationDays(string $stripePriceId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT grants_duration_days FROM prices WHERE stripe_price_id = ? LIMIT 1'
        );
        $stmt->execute([$stripePriceId]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? (int) $val : null;
    }
}
