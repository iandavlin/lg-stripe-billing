<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

interface AdminActionLogRepository
{
    /**
     * Append a row to admin_action_log. actor_wp_user is NULL for system /
     * customer-initiated actions (e.g. regional billing verification).
     */
    public function log(
        int     $customerId,
        string  $action,
        ?string $subId,
        string  $reason,
        bool    $success,
        ?string $errorMessage = null,
    ): void;
}
