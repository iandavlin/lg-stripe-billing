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
        ?array $recipients = null,
    ): array {
        $codes = $this->generateUniqueCodes($count);

        $placeholders = implode(', ', array_fill(0, $count, '(?, ?, ?, ?, ?, ?, ?, ?)'));
        $stmt = $this->pdo->prepare(
            "INSERT INTO gift_codes
                 (code, tier, duration_days, purchased_by, stripe_session_id,
                  recipient_email, recipient_name, gift_message)
             VALUES {$placeholders}"
        );

        $params = [];
        foreach ($codes as $i => $code) {
            $r = $recipients[$i] ?? null;
            $params[] = $code;
            $params[] = $tier;
            $params[] = $durationDays;
            $params[] = $purchasedBy;
            $params[] = $stripeSessionId;
            $params[] = self::nonEmpty($r['email']   ?? null);
            $params[] = self::nonEmpty($r['name']    ?? null);
            $params[] = self::nonEmpty($r['message'] ?? null);
        }
        $stmt->execute($params);

        // Fetch back so we have full rows with IDs and created_at
        $in  = implode(', ', array_fill(0, $count, '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM gift_codes WHERE code IN ({$in})");
        $stmt->execute($codes);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'toDto'], $rows);
    }

    public function markEmailSent(int $giftCodeId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE gift_codes SET email_sent_at = NOW() WHERE id = ? AND email_sent_at IS NULL'
        );
        $stmt->execute([$giftCodeId]);
    }

    private static function nonEmpty(?string $v): ?string
    {
        if ($v === null) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
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

    public function voidByStripeSessionId(string $stripeSessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, redeemed_at FROM gift_codes
             WHERE stripe_session_id = ? AND voided_at IS NULL'
        );
        $stmt->execute([$stripeSessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $voided          = [];
        $alreadyRedeemed = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($row['redeemed_at'] !== null) {
                $alreadyRedeemed[] = $id;
                continue;
            }
            $voided[] = $id;
        }

        if ($voided !== []) {
            $in   = implode(', ', array_fill(0, count($voided), '?'));
            $stmt = $this->pdo->prepare(
                "UPDATE gift_codes SET voided_at = NOW() WHERE id IN ({$in})"
            );
            $stmt->execute($voided);
        }

        return ['voided' => $voided, 'already_redeemed' => $alreadyRedeemed];
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
            voidedAt:        isset($row['voided_at']) && $row['voided_at'] !== null ? new DateTimeImmutable((string) $row['voided_at']) : null,
            createdAt:       new DateTimeImmutable((string) $row['created_at']),
            recipientEmail:  isset($row['recipient_email']) && $row['recipient_email'] !== null ? (string) $row['recipient_email'] : null,
            recipientName:   isset($row['recipient_name'])  && $row['recipient_name']  !== null ? (string) $row['recipient_name']  : null,
            giftMessage:     isset($row['gift_message'])    && $row['gift_message']    !== null ? (string) $row['gift_message']    : null,
            emailSentAt:     isset($row['email_sent_at'])   && $row['email_sent_at']   !== null ? new DateTimeImmutable((string) $row['email_sent_at']) : null,
        );
    }
}
