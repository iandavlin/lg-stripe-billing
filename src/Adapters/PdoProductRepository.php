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

    public function pricePerYearCentsForTier(string $tier): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT pr.unit_amount_cents
             FROM prices pr
             JOIN products p ON p.id = pr.product_id
             WHERE p.kind = 'membership'
               AND p.active = 1
               AND p.ref = ?
               AND pr.active = 1
               AND pr.`interval` = 'year'
             ORDER BY (pr.region_tag IS NULL) DESC, pr.priority ASC
             LIMIT 1"
        );
        $stmt->execute([$tier]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null ? (int) $val : null;
    }

    public function findPriceData(string $stripePriceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pr.unit_amount_cents, pr.currency, pr.`interval`, pr.grants_duration_days,
                    p.name AS product_name
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
                  currency, region_tag, priority, grants_duration_days, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 type                 = VALUES(type),
                 `interval`           = VALUES(`interval`),
                 unit_amount_cents    = VALUES(unit_amount_cents),
                 currency             = VALUES(currency),
                 region_tag           = VALUES(region_tag),
                 priority             = VALUES(priority),
                 grants_duration_days = VALUES(grants_duration_days),
                 active               = VALUES(active)"
        );
        $stmt->execute([
            $productId, $stripePriceId, $type, $interval, $unitAmountCents,
            $currency, $regionTag, $priority, $grantsDurationDays, (int) $active,
        ]);
    }

    public function listMembership(?string $countryCode = null): array
    {
        $country = $countryCode !== null ? strtoupper(trim($countryCode)) : '';

        // Fetch candidate prices: defaults (region_tag IS NULL) plus any
        // regional prices that match the country. Sort by priority so the
        // lowest-priority match per (product, type, interval) wins.
        $stmt = $this->pdo->prepare(
            "SELECT p.id AS product_id, p.stripe_product_id, p.name, p.ref,
                    pr.stripe_price_id, pr.type, pr.interval, pr.unit_amount_cents,
                    pr.currency, pr.region_tag, pr.grants_duration_days, pr.priority
             FROM products p
             JOIN prices pr ON pr.product_id = p.id AND pr.active = 1
             LEFT JOIN price_regions r
                ON r.region_tag = pr.region_tag AND r.country_code = ?
             WHERE p.kind = 'membership' AND p.active = 1
               AND (pr.region_tag IS NULL OR r.country_code IS NOT NULL)
             ORDER BY p.id ASC, pr.priority ASC"
        );
        $stmt->execute([$country]);

        $map  = [];
        $seen = []; // stripe_product_id => [type|interval => true]
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (string) $row['stripe_product_id'];
            if (!isset($map[$pid])) {
                $map[$pid] = [
                    'stripe_product_id' => $pid,
                    'name'              => $row['name'],
                    'ref'               => $row['ref'],
                    'prices'            => [],
                ];
                $seen[$pid] = [];
            }
            $key = ($row['type'] ?? '') . '|' . ($row['interval'] ?? '');
            if (isset($seen[$pid][$key])) {
                continue; // already picked a better-priority winner for this combo
            }
            $seen[$pid][$key] = true;
            $map[$pid]['prices'][] = [
                'stripe_price_id'      => $row['stripe_price_id'],
                'type'                 => $row['type'],
                'interval'             => $row['interval'],
                'unit_amount_cents'    => (int) $row['unit_amount_cents'],
                'currency'             => $row['currency'],
                'region_tag'           => $row['region_tag'],
                'grants_duration_days' => $row['grants_duration_days'] !== null ? (int) $row['grants_duration_days'] : null,
            ];
        }

        return array_values($map);
    }
}
