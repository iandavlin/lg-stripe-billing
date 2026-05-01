<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Domain\Repositories\AdminActionLogRepository;
use PDO;

final class PdoAdminActionLogRepository implements AdminActionLogRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function log(
        int     $customerId,
        string  $action,
        ?string $subId,
        string  $reason,
        bool    $success,
        ?string $errorMessage = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_action_log
                 (customer_id, actor_wp_user, action, sub_id, reason, success, error_message)
             VALUES (?, NULL, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $customerId,
            $action,
            $subId,
            $reason,
            (int) $success,
            $errorMessage,
        ]);
    }
}
