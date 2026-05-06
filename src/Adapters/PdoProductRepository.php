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
        // Regional routing is now at the product level (see regionTagForPrice /
        // listMembership). For standard prices, return unchanged.
        return $stripePriceId;
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

    public function pricePerYearCentsForTier(string $tier): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT pr.unit_amount_cents
             FROM prices pr
             JOIN products p ON p.id = pr.product_id
             WHERE p.kind = 'membership'
               AND p.active = 1
               AND p.ref = ?
               AND p.region_tag IS NULL
               AND pr.active = 1
               AND pr.`interval` = 'year'
             LIMIT 1"
        );
        $stmt->execute([$tier]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null ? (int) $val : null;
    }

    public function findPriceData(string $stripePriceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pr.unit_amount_cents, pr.currency, pr.`interval`, pr.grants_duration_days, pr.discount_scale, pr.trial_days,
                    p.name AS product_name, p.region_tag AS product_region_tag
             FROM prices pr
             JOIN products p ON p.id = pr.product_id
             WHERE pr.stripe_price_id = ? LIMIT 1'
        );
        $stmt->execute([$stripePriceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'unit_amount_cents'    => (int) $row['unit_amount_cents'],
            'currency'             => (string) $row['currency'],
            'interval'             => $row['interval'] !== null ? (string) $row['interval'] : null,
            'grants_duration_days' => $row['grants_duration_days'] !== null ? (int) $row['grants_duration_days'] : null,
            'product_name'         => (string) $row['product_name'],
            'product_region_tag'   => $row['product_region_tag'] !== null ? (string) $row['product_region_tag'] : null,
            'discount_scale'       => $row['discount_scale'] !== null ? (float) $row['discount_scale'] : 1.0,
            'trial_days'           => (int) ($row['trial_days'] ?? 0),
        ];
    }

    public function upsertProduct(
        string  $stripeProductId,
        string  $name,
        string  $kind,
        ?string $ref,
        bool    $active,
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO products (stripe_product_id, kind, ref, name, active)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 name   = VALUES(name),
                 active = VALUES(active)"
        );
        $stmt->execute([$stripeProductId, $kind, $ref, $name, (int) $active]);
    }

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
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM products WHERE stripe_product_id = ? LIMIT 1'
        );
        $stmt->execute([$stripeProductId]);
        $productId = $stmt->fetchColumn();
        if ($productId === false) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO prices
                 (product_id, stripe_price_id, type, `interval`, unit_amount_cents,
                  currency, region_tag, priority, grants_duration_days, discount_scale, trial_days, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 type                 = VALUES(type),
                 `interval`           = VALUES(`interval`),
                 unit_amount_cents    = VALUES(unit_amount_cents),
                 currency             = VALUES(currency),
                 region_tag           = VALUES(region_tag),
                 priority             = VALUES(priority),
                 grants_duration_days = VALUES(grants_duration_days),
                 discount_scale       = VALUES(discount_scale),
                 trial_days           = VALUES(trial_days),
                 active               = VALUES(active)"
        );
        $stmt->execute([
            $productId, $stripePriceId, $type, $interval, $unitAmountCents,
            $currency, $regionTag, $priority, $grantsDurationDays, $discountScale, $trialDays, (int) $active,
        ]);
    }

    public function listMembership(?string $countryCode = null): array
    {
        $country = $countryCode !== null ? strtoupper(trim($countryCode)) : '';

        // Fetch all eligible products: standard (region_tag IS NULL) are always
        // included; regional products are included only when the country is mapped
        // to that region_tag in price_regions.
        $stmt = $this->pdo->prepare(
            "SELECT p.id AS product_id, p.stripe_product_id, p.name, p.ref, p.region_tag,
                    pr.stripe_price_id, pr.type, pr.interval, pr.unit_amount_cents,
                    pr.currency, pr.grants_duration_days, pr.discount_scale, pr.trial_days
             FROM products p
             JOIN prices pr ON pr.product_id = p.id AND pr.active = 1
             LEFT JOIN price_regions r
                ON r.region_tag = p.region_tag AND r.country_code = ?
             WHERE p.kind = 'membership' AND p.active = 1
               AND (p.region_tag IS NULL OR r.country_code IS NOT NULL)
             ORDER BY p.id ASC"
        );
        $stmt->execute([$country]);

        // Group rows by product, then by tier ref.
        // For each ref (tier), prefer the regional product over standard so that
        // a regional customer sees only their discounted pricing, not both.
        $productRows = [];   // stripe_product_id → product data
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (string) $row['stripe_product_id'];
            if (!isset($productRows[$pid])) {
                $productRows[$pid] = [
                    'stripe_product_id' => $pid,
                    'name'              => $row['name'],
                    'ref'               => (string) ($row['ref'] ?? ''),
                    'region_tag'        => $row['region_tag'],
                    'prices'            => [],
                ];
            }
            $productRows[$pid]['prices'][] = [
                'stripe_price_id'      => $row['stripe_price_id'],
                'type'                 => $row['type'],
                'interval'             => $row['interval'],
                'unit_amount_cents'    => (int) $row['unit_amount_cents'],
                'currency'             => $row['currency'],
                'region_tag'           => $row['region_tag'],
                'grants_duration_days' => $row['grants_duration_days'] !== null ? (int) $row['grants_duration_days'] : null,
                'discount_scale'       => $row['discount_scale'] !== null ? (float) $row['discount_scale'] : 1.0,
                'trial_days'           => (int) ($row['trial_days'] ?? 0),
            ];
        }

        // For each tier ref, keep the regional product if one is present;
        // fall back to the standard product.
        $byRef = []; // ref → chosen product data
        foreach ($productRows as $prod) {
            $ref = (string) ($prod['ref'] ?? '');
            if (!isset($byRef[$ref]) || $prod['region_tag'] !== null) {
                $byRef[$ref] = $prod;
            }
        }

        return array_values($byRef);
    }

    public function regionTagForPrice(string $stripePriceId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.region_tag
             FROM prices pr
             JOIN products p ON p.id = pr.product_id
             WHERE pr.stripe_price_id = ? LIMIT 1'
        );
        $stmt->execute([$stripePriceId]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? (string) $val : null;
    }

    public function countryInRegion(string $countryCode, string $regionTag): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM price_regions WHERE country_code = ? AND region_tag = ? LIMIT 1'
        );
        $stmt->execute([strtoupper($countryCode), $regionTag]);
        return $stmt->fetchColumn() !== false;
    }

    public function standardPriceForTierAndInterval(string $regionalPriceId): ?string
    {
        // Get the tier ref, type, and interval of the regional price.
        $stmt = $this->pdo->prepare(
            'SELECT p.ref, pr.`interval`, pr.type
             FROM prices pr
             JOIN products p ON p.id = pr.product_id
             WHERE pr.stripe_price_id = ? LIMIT 1'
        );
        $stmt->execute([$regionalPriceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // Find the matching standard price (region_tag IS NULL on the product).
        $stmt = $this->pdo->prepare(
            "SELECT pr.stripe_price_id
             FROM prices pr
             JOIN products p ON p.id = pr.product_id
             WHERE p.ref = ?
               AND p.region_tag IS NULL
               AND p.active = 1
               AND pr.type = ?
               AND (pr.`interval` <=> ?)
               AND pr.active = 1
             LIMIT 1"
        );
        $stmt->execute([$row['ref'], $row['type'], $row['interval']]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : null;
    }
}
