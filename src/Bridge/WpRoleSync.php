<?php

declare(strict_types=1);

namespace LGSB\Bridge;

/**
 * Transitional bridge: pushes role state from lg_membership → wp_usermeta.
 *
 * Implementations read active entitlements for a customer, compute the
 * appropriate WP role, and write wp_capabilities while preserving
 * non-tier roles (administrator, bbp_participant, etc.).
 *
 * Deletable once WordPress is retired — remove the constructor param
 * from anything that injects it.
 */
interface WpRoleSync
{
    /** Sync one customer's role to WP now. No-op if no wp_user_bridge row. */
    public function sync(int $customerId): void;

    /** Sweep all bridged customers, fixing drift. Used by the Reconciler. */
    public function syncAll(): SyncSummary;
}
