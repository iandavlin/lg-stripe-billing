<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use DateTimeImmutable;
use LGSB\Domain\GiftCode;
use LGSB\Domain\Repositories\GiftCodeRepository;
use PDO;
use RuntimeException;

final class PdoGiftCodeRepository implements GiftCodeRepository
{
    // Unambiguous uppercase alphabet: no 0/O, 1/I/L
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly PDO $pdo) {}

    public function findByCode(string $code): ?GiftCode
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gift_codes WHERE code = ? LIMIT 1');
        $stmt->execute([strtoupper($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::toDto($row) : null;
    }

    public function createBatch(
        int    $count,
        int    $purchasedBy,
        string $tier,
        int    $durationDays,
        string $stripeSessionId,
    ): array {
        $codes = $this->generateUniqueCodes($count);

        $placeholders = implode(', ', array_fill(0, $count, '(?, ?, ?, ?, ?)'));
        $stmt = $this->pdo->prepare(
            "INSERT INTO gift_codes (code, tier, duration_days, purchased_by, stripe_session_id)
             VALUES {$placeholders}"
        );

        $params = [];
        foreach ($codes as $code) {
            $params[] = $code;
            $params[] = $tier;
            $params[] = $durationDays;
            $params[] = $purchasedBy;
            $params[] = $stripeSessionId;
        }
        $stmt->execute($params);

        // Fetch back so we have full rows with IDs and created_at
        $in  = implode(', ', array_fill(0, $count, '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM gift_codes WHERE code IN ({$in})");
        $stmt->execute($codes);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'toDto'], $rows);
    }

    public function redeem(int $giftCodeId, int $redeemedBy): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE gift_codes
             SET redeemed_by = ?, redeemed_at = NOW()
             WHERE id = ? AND redeemed_at IS NULL'
        );
        $stmt->execute([$redeemedBy, $giftCodeId]);
    }

    /** @return string[] */
    private function generateUniqueCodes(int $count): array
    {
        $codes   = [];
        $maxTries = $count * 10;
        $tries    = 0;

        while (count($codes) < $count && $tries++ < $maxTries) {
            $candidate = $this->randomCode();
            // Check DB for collision
            $stmt = $this->pdo->prepare('SELECT 1 FROM gift_codes WHERE code = ? LIMIT 1');
            $stmt->execute([$candidate]);
            if ($stmt->fetchColumn() === false && !in_array($candidate, $codes, true)) {
                $codes[] = $candidate;
            }
        }

        if (count($codes) < $count) {
            throw new RuntimeException('Failed to generate enough unique gift codes.');
        }

        return $codes;
    }

    private function randomCode(): string
    {
        $bytes = random_bytes(12);
        $code  = '';
        for ($i = 0; $i < 12; $i++) {
            // ALPHABET has 32 chars = 2^5, so % 32 on a byte is perfectly uniform
            $code .= self::ALPHABET[ord($bytes[$i]) % 32];
        }
        return $code;
    }

    private static function toDto(array $row): GiftCode
    {
        return new GiftCode(
            id:              (int) $row['id'],
            code:            (string) $row['code'],
            tier:            (string) $row['tier'],
            durationDays:    (int) $row['duration_days'],
            purchasedBy:     (int) $row['purchased_by'],
            redeemedBy:      $row['redeemed_by'] !== null ? (int) $row['redeemed_by'] : null,
            stripeSessionId: $row['stripe_session_id'] !== null ? (string) $row['stripe_session_id'] : null,
            redeemedAt:      $row['redeemed_at'] !== null ? new DateTimeImmutable((string) $row['redeemed_at']) : null,
            createdAt:       new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
