<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Domain\Repositories\BannedEmailsRepository;
use PDO;

final class PdoBannedEmailsRepository implements BannedEmailsRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function isBanned(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM banned_emails WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        return (bool) $stmt->fetchColumn();
    }

    public function findReason(string $email): ?string
    {
        $stmt = $this->pdo->prepare('SELECT reason FROM banned_emails WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return $row['reason'] !== null ? (string) $row['reason'] : '';
    }

    public function ban(string $email, ?string $reason = null, ?int $bannedByWpUser = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO banned_emails (email, reason, banned_by_wp) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), banned_by_wp = VALUES(banned_by_wp)'
        );
        $stmt->execute([
            strtolower(trim($email)),
            $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            $bannedByWpUser,
        ]);
    }

    public function unban(string $email): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM banned_emails WHERE email = ?');
        $stmt->execute([strtolower(trim($email))]);
    }
}
