<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Bridge\SyncSummary;
use LGSB\Bridge\WpRoleSync;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Periodic drift-correction. Runs on a cron (hourly or daily).
 *
 * Today's behavior: defer to WpRoleSync::syncAll() which reads each
 * bridged customer's active entitlements and corrects any WP role
 * drift. Expired one-time entitlements are filtered by the repository
 * layer, so they drop out naturally.
 *
 * A future pass can add Stripe drift verification (for any customer
 * with an active subscription-sourced entitlement, confirm Stripe still
 * reports an active subscription — catches webhook delivery failures).
 */
class Reconciler
{
    public function __construct(
        private readonly WpRoleSync       $wpSync,
        private readonly LoggerInterface  $logger = new NullLogger(),
    ) {}

    public function run(): SyncSummary
    {
        $this->logger->info('Reconciler starting');
        $summary = $this->wpSync->syncAll();
        $this->logger->info('Reconciler complete', [
            'checked'   => $summary->checked,
            'updated'   => $summary->updated,
            'unchanged' => $summary->unchanged,
            'errors'    => count($summary->errors),
        ]);
        return $summary;
    }
}
