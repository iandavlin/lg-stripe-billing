<?php

declare(strict_types=1);

namespace LGSB\Contracts;

interface Notifier
{
    public function sendWelcomeEmail(string $toEmail, string $resetUrl): void;

    /**
     * @param array{checked:int, ok:int, downgraded:array, errors:array, skipped:int} $results
     */
    public function sendReconciliationSummary(string $adminEmail, string $siteName, array $results): void;

    public function tagUser(int $userId, string $tagName): void;
}
