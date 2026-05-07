#!/usr/bin/env php
<?php
/**
 * Retention bonus poller.
 * Run manually or via cron (monthly is plenty).
 *
 * For each affiliate conversion that hit the 1-year mark:
 *   1. Checks Stripe to confirm the customer still has an active subscription.
 *   2. Sums all paid invoices for that customer between converted_at and
 *      converted_at + 1 year (their actual first-year spend).
 *   3. Applies retention_bonus_pct to that real total.
 *   4. Prints a payout report by affiliate.
 *   5. Marks each eligible conversion in the DB (unless --dry-run).
 *
 * Usage:
 *   php bin/poll-retention.php [--dry-run]
 */

require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();

use LGSB\Adapters\PdoAffiliateRepository;
use Stripe\StripeClient;

$dryRun = in_array('--dry-run', $argv ?? [], true);

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME'] ?? '',
    ),
    $_ENV['DB_USER']     ?? '',
    $_ENV['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

$repo   = new PdoAffiliateRepository($pdo);
$stripe = new StripeClient($_ENV['STRIPE_SECRET_KEY'] ?? '');

$candidates = $repo->retentionCandidates();

if (empty($candidates)) {
    echo "No retention candidates found.\n";
    exit(0);
}

echo sprintf("Found %d candidate(s). Checking Stripe...\n\n", count($candidates));

$payouts = [];

foreach ($candidates as $row) {
    $stripeCustomerId = $row['stripe_customer_id'];
    $bonusPct         = (float) $row['retention_bonus_pct'];
    $email            = $row['email'] ?? $stripeCustomerId;
    $convertedAt      = new DateTimeImmutable($row['converted_at']);
    $yearEnd          = $convertedAt->modify('+1 year');
    $conversionId     = (int) $row['id'];

    // Confirm still subscribed.
    try {
        $subs = $stripe->subscriptions->all([
            'customer' => $stripeCustomerId,
            'status'   => 'active',
            'limit'    => 1,
        ]);
    } catch (\Throwable $e) {
        echo "  SKIP  {$email} — Stripe error: {$e->getMessage()}\n";
        continue;
    }

    if (empty($subs->data)) {
        echo "  SKIP  {$email} — no active subscription (churned)\n";
        continue;
    }

    // Sum all paid invoices in the first year.
    $totalCents = 0;
    $params = [
        'customer' => $stripeCustomerId,
        'status'   => 'paid',
        'created'  => [
            'gte' => $convertedAt->getTimestamp(),
            'lte' => $yearEnd->getTimestamp(),
        ],
        'limit' => 100,
    ];

    try {
        do {
            $invoices = $stripe->invoices->all($params);
            foreach ($invoices->data as $inv) {
                $totalCents += (int) ($inv->amount_paid ?? 0);
            }
            if ($invoices->has_more) {
                $params['starting_after'] = end($invoices->data)->id;
            }
        } while ($invoices->has_more);
    } catch (\Throwable $e) {
        echo "  SKIP  {$email} — invoice fetch error: {$e->getMessage()}\n";
        continue;
    }

    $totalUsd    = $totalCents / 100;
    $bonusAmount = round($totalUsd * ($bonusPct / 100), 2);

    echo sprintf("  ELIGIBLE  %-30s  affiliate: %-15s  year total: $%.2f  bonus: $%.2f (%s%%)\n",
        $email, $row['slug'], $totalUsd, $bonusAmount, $bonusPct
    );

    $payouts[] = [
        'conversion_id'      => $conversionId,
        'affiliate_slug'     => $row['slug'],
        'affiliate_label'    => $row['label'],
        'customer_email'     => $email,
        'stripe_customer_id' => $stripeCustomerId,
        'converted_at'       => $row['converted_at'],
        'year_total_usd'     => $totalUsd,
        'bonus_pct'          => $bonusPct,
        'bonus_amount_usd'   => $bonusAmount,
    ];

    if (!$dryRun) {
        $repo->markRetentionEligible($conversionId);
    }
}

echo "\n";
echo str_repeat('─', 60) . "\n";
echo sprintf("  %d eligible for retention bonus\n", count($payouts));

if (!empty($payouts)) {
    $total = array_sum(array_column($payouts, 'bonus_amount_usd'));
    echo sprintf("  Total payout owed: $%.2f\n", $total);
    echo "\n  By affiliate:\n";

    $byAffiliate = [];
    foreach ($payouts as $p) {
        $byAffiliate[$p['affiliate_label']][] = $p;
    }
    foreach ($byAffiliate as $label => $rows) {
        $subtotal = array_sum(array_column($rows, 'bonus_amount_usd'));
        echo sprintf("    %-20s  %d conversion(s)  $%.2f\n",
            $label, count($rows), $subtotal
        );
    }
}

if ($dryRun) {
    echo "\n  [dry-run — no DB changes made]\n";
}

echo str_repeat('─', 60) . "\n\n";
exit(0);
