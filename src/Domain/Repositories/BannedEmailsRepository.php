<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

interface BannedEmailsRepository
{
    public function isBanned(string $email): bool;

    public function findReason(string $email): ?string;

    public function ban(string $email, ?string $reason = null, ?int $bannedByWpUser = null): void;

    public function unban(string $email): void;
}
