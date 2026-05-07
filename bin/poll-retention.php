#!/usr/bin/env php
<?php
/**
 * Retention bonus poller.
 * Run manually or via cron (monthly is plenty).
 *
 * For each affiliate conversion that hit the 1-year mark:
 *   1. Checks Stripe to confirm the customer still has an active subscription.
 *   2. Marks the conversion retention_bonus_eligible_at = NOW().
 *   3. Prints a payout report.
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
        $_ENV['DB_HOST']     ?? '127.0.0.1',
        $_ENV['DB_PORT']     ?? '3306',
        $_ENV['DB_NAME']     ?? '',
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
    $slug             = $row['slug'];
    $label            = $row['label'];
    $bonusPct         = (float) $row['retention_bonus_pct'];
    $email            = $row['email'] ?? $stripeCustomerId;
    $convertedAt      = $row['converted_at'];
    $conversionId     = (int) $row['id'];

    // Check for active subscription on this Stripe customer.
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

    $sub        = $subs->data[0];
    $planAmount = $sub->items->data[0]->price->unit_amount ?? 0; // cents
    $interval   = $sub->items->data[0]->price->recurring->interval ?? 'month';
    // Annualise for bonus calculation: monthly × 12 or annual × 1
    $annualCents = $interval === 'year' ? $planAmount : $planAmount * 12;
    $bonusAmount = round(($annualCents / 100) * ($bonusPct / 100), 2);

    echo sprintf("  ELIGIBLE  %-30s  affiliate: %-15s  bonus: $%.2f (%s%%)\n",
        $email, $slug, $bonusAmount, $bonusPct
    );

    $payouts[] = [
        'conversion_id'      => $conversionId,
        'affiliate_slug'     => $slug,
        'affiliate_label'    => $label,
        'customer_email'     => $email,
        'stripe_customer_id' => $stripeCustomerId,
        'converted_at'       => $convertedAt,
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
    foreach ($byAffiliate as $affiliateLabel => $rows) {
        $subtotal = array_sum(array_column($rows, 'bonus_amount_usd'));
        echo sprintf("    %-20s  %d conversion(s)  $%.2f\n",
            $affiliateLabel, count($rows), $subtotal
        );
    }
}

if ($dryRun) {
    echo "\n  [dry-run — no DB changes made]\n";
}

echo str_repeat('─', 60) . "\n\n";
exit(0);
