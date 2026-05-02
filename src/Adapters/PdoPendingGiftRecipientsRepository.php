<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Domain\Repositories\PendingGiftRecipientsRepository;
use PDO;

final class PdoPendingGiftRecipientsRepository implements PendingGiftRecipientsRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function store(string $stripeCheckoutSessionId, array $recipients): void
    {
        if ($recipients === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($recipients), '(?, ?, ?, ?, ?)'));
        $stmt = $this->pdo->prepare(
            "INSERT INTO gift_recipients_pending
                 (stripe_checkout_session_id, position, recipient_email, recipient_name, gift_message)
             VALUES {$placeholders}"
        );

        $params = [];
        foreach ($recipients as $i => $r) {
            $params[] = $stripeCheckoutSessionId;
            $params[] = $i;
            $params[] = self::nonEmpty($r['email']   ?? null);
            $params[] = self::nonEmpty($r['name']    ?? null);
            $params[] = self::nonEmpty($r['message'] ?? null);
        }
        $stmt->execute($params);
    }

    public function consume(string $stripeCheckoutSessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT position, recipient_email, recipient_name, gift_message
             FROM gift_recipients_pending
             WHERE stripe_checkout_session_id = ?
             ORDER BY position ASC'
        );
        $stmt->execute([$stripeCheckoutSessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'email'   => $row['recipient_email'] !== null ? (string) $row['recipient_email'] : null,
                'name'    => $row['recipient_name']  !== null ? (string) $row['recipient_name']  : null,
                'message' => $row['gift_message']    !== null ? (string) $row['gift_message']    : null,
            ];
        }

        // Delete after read — these rows are single-use.
        $stmt = $this->pdo->prepare(
            'DELETE FROM gift_recipients_pending WHERE stripe_checkout_session_id = ?'
        );
        $stmt->execute([$stripeCheckoutSessionId]);

        return $out;
    }

    private static function nonEmpty(?string $v): ?string
    {
        if ($v === null) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
    }
}
