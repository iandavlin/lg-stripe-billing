#!/usr/bin/env php
<?php

/**
 * Idempotent Stripe catalog importer.
 *
 *   php bin/stripe-import-catalog.php db/catalog.json
 *
 * Reads a JSON catalog (db/catalog.json) and creates the corresponding
 * Stripe products and prices in whichever Stripe mode the configured
 * STRIPE_SECRET_KEY targets (test or live).
 *
 * Idempotency:
 *   - Products matched by metadata.ref. If a product with the same ref
 *     already exists, no new product is created.
 *   - Prices matched by (type, interval, unit_amount, currency) within
 *     the product. Duplicates are skipped.
 *
 * Catalog fields:
 *   ref         — Stripe metadata.ref (must be unique across all products)
 *   db_ref      — DB products.ref override (defaults to ref if absent)
 *                 Use this for regional products that share a tier slug with
 *                 their standard counterpart (e.g. db_ref: "looth2")
 *   region_tag  — Written into products.region_tag via the SQL stamp
 *                 (null/absent = standard tier)
 *   name        — Stripe product name (what customers see)
 *   kind        — DB products.kind (default: "membership")
 *
 * Output:
 *   - Console progress log.
 *   - A block of SQL UPDATE statements at the end. Run those against
 *     the lg_membership DB to stamp our internal ref/kind/region_tag
 *     columns and prices.grants_duration_days (Stripe has no native concept).
 *
 * The webhook handles inserting product/price rows into our DB on
 * `product.created`/`price.created` events. This script just creates
 * the Stripe-side objects; the webhook syncs them down.
 *
 * For prod cutover: run with STRIPE_SECRET_KEY set to the live key.
 * Same script, different env.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Stripe\StripeClient;

/* ----------------------------- args + env ------------------------------ */

$catalogPath = $argv[1] ?? null;
if ($catalogPath === null || !is_file($catalogPath)) {
    fwrite(STDERR, "Usage: php bin/stripe-import-catalog.php <catalog.json>\n");
    exit(1);
}

$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

$key = (string) ($_ENV['STRIPE_SECRET_KEY'] ?? '');
if ($key === '') {
    fwrite(STDERR, "STRIPE_SECRET_KEY not set (checked .env and \$_ENV).\n");
    exit(1);
}
$mode = str_starts_with($key, 'sk_live_') ? 'LIVE' : 'TEST';

/* ----------------------------- catalog --------------------------------- */

$catalog = json_decode((string) file_get_contents($catalogPath), true);
if (!is_array($catalog) || !isset($catalog['products']) || !is_array($catalog['products'])) {
    fwrite(STDERR, "Invalid catalog JSON (missing or bad 'products' array).\n");
    exit(1);
}

/* ----------------------------- run ------------------------------------- */

fwrite(STDERR, sprintf("Stripe mode: %s\nCatalog: %s\n\n", $mode, $catalogPath));

$stripe = new StripeClient($key);

// Pre-fetch all products in one paginated request — saves N round trips.
$existingProducts = [];
foreach ($stripe->products->all(['limit' => 100, 'active' => true])->autoPagingIterator() as $p) {
    $existingProducts[(string) $p->id] = $p;
}

$sqlStamps = [];

foreach ($catalog['products'] as $entry) {
    $ref       = (string) ($entry['ref']        ?? '');
    $dbRef     = (string) ($entry['db_ref']     ?? $ref);   // DB ref; defaults to Stripe ref
    $name      = (string) ($entry['name']       ?? '');
    $kind      = (string) ($entry['kind']       ?? 'membership');
    $regionTag = isset($entry['region_tag']) ? (string) $entry['region_tag'] : null;

    if ($ref === '' || $name === '') {
        fwrite(STDERR, "Skipping malformed product entry: " . json_encode($entry) . "\n");
        continue;
    }

    // Find by metadata.ref (the Stripe-side unique ref, NOT db_ref).
    $product = null;
    foreach ($existingProducts as $p) {
        if ((string) ($p->metadata->ref ?? '') === $ref) {
            $product = $p;
            break;
        }
    }

    if ($product === null) {
        $product = $stripe->products->create([
            'name'     => $name,
            'metadata' => ['ref' => $ref, 'kind' => $kind],
        ]);
        echo "  + product {$ref} created → {$product->id}\n";
    } else {
        echo "  = product {$ref} exists → {$product->id}\n";
    }

    $regionTagSql = $regionTag !== null
        ? ", region_tag = '" . addslashes($regionTag) . "'"
        : ', region_tag = NULL';

    $sqlStamps[] = sprintf(
        "UPDATE products SET ref = '%s', kind = '%s'%s WHERE stripe_product_id = '%s';",
        addslashes($dbRef),
        addslashes($kind),
        $regionTagSql,
        $product->id,
    );

    // Pre-fetch prices for this product
    $existingPrices = [];
    foreach ($stripe->prices->all(['product' => $product->id, 'limit' => 100])->autoPagingIterator() as $pr) {
        $existingPrices[] = $pr;
    }

    foreach (($entry['prices'] ?? []) as $priceSpec) {
        $type     = (string) ($priceSpec['type'] ?? '');
        $interval = $priceSpec['interval'] ?? null;
        $amount   = (int)    ($priceSpec['unit_amount_cents'] ?? 0);
        $currency = strtolower((string) ($priceSpec['currency'] ?? 'usd'));
        $key2     = (string) ($priceSpec['key'] ?? '');
        $duration = isset($priceSpec['grants_duration_days']) ? (int) $priceSpec['grants_duration_days'] : null;

        if ($type === '' || $amount <= 0) {
            fwrite(STDERR, "    Skipping malformed price entry: " . json_encode($priceSpec) . "\n");
            continue;
        }

        // Match by type + interval + amount + currency + active
        $matched = null;
        foreach ($existingPrices as $ep) {
            if (! ($ep->active ?? true)) {
                continue;
            }
            $epType     = (string) $ep->type;
            $epInterval = $ep->recurring->interval ?? null;
            if ($epType === $type
                && $epInterval === $interval
                && (int) $ep->unit_amount === $amount
                && strtolower((string) $ep->currency) === $currency
            ) {
                $matched = $ep;
                break;
            }
        }

        if ($matched !== null) {
            echo "    = price {$key2} exists → {$matched->id}\n";
            $stripePriceId = (string) $matched->id;
        } else {
            $params = [
                'product'     => $product->id,
                'currency'    => $currency,
                'unit_amount' => $amount,
                'metadata'    => array_filter([
                    'key'                  => $key2 !== '' ? $key2 : null,
                    'grants_duration_days' => $duration !== null ? (string) $duration : null,
                ], static fn ($v) => $v !== null),
            ];
            if ($type === 'recurring') {
                if (!is_string($interval) || $interval === '') {
                    fwrite(STDERR, "    Skipping recurring price with no interval: {$key2}\n");
                    continue;
                }
                $params['recurring'] = ['interval' => $interval];
            }
            $created       = $stripe->prices->create($params);
            $stripePriceId = (string) $created->id;
            echo "    + price {$key2} created → {$stripePriceId} (\${$amount}/100 {$currency})\n";
        }

        if ($duration !== null) {
            $sqlStamps[] = sprintf(
                "UPDATE prices SET grants_duration_days = %d WHERE stripe_price_id = '%s';",
                $duration,
                $stripePriceId,
            );
        }
    }

    echo "\n";
}

/* ----------------------------- SQL output ------------------------------ */

echo "─────────────────────────────────────────────────────────────────\n";
echo " SQL stamps — run on lg_membership DB after webhook has synced:\n";
echo "─────────────────────────────────────────────────────────────────\n";
foreach ($sqlStamps as $sql) {
    echo $sql . "\n";
}
echo "─────────────────────────────────────────────────────────────────\n";
echo "Done. Stripe mode: {$mode}\n";
