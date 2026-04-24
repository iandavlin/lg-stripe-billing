<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use DateTimeImmutable;
use LGSB\Domain\Repositories\SubscriptionRepository;
use LGSB\Domain\Subscription;
use PDO;
use RuntimeException;

final class PdoSubscriptionRepository implements SubscriptionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findById(int $id): ?Subscription
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subscriptions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::toDto($row) : null;
    }

    public function findByStripeId(string $stripeSubscriptionId): ?Subscription
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1'
        );
        $stmt->execute([$stripeSubscriptionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::toDto($row) : null;
    }

    public function findActiveForCustomer(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM subscriptions
             WHERE customer_id = ?
               AND status IN ('active', 'trialing', 'past_due')
             ORDER BY id DESC"
        );
        $stmt->execute([$customerId]);
        return array_map([self::class, 'toDto'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function upsert(
        int                $customerId,
        string             $stripeSubscriptionId,
        string             $stripePriceId,
        string             $status,
        bool               $cancelAtPeriodEnd,
        ?DateTimeImmutable $currentPeriodStart,
        ?DateTimeImmutable $currentPeriodEnd,
        ?DateTimeImmutable $canceledAt,
    ): Subscription {
        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions
                (customer_id, stripe_subscription_id, stripe_price_id, status,
                 cancel_at_period_end, current_period_start, current_period_end, canceled_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                stripe_price_id      = VALUES(stripe_price_id),
                status               = VALUES(status),
                cancel_at_period_end = VALUES(cancel_at_period_end),
                current_period_start = VALUES(current_period_start),
                current_period_end   = VALUES(current_period_end),
                canceled_at          = VALUES(canceled_at)'
        );
        $stmt->execute([
            $customerId,
            $stripeSubscriptionId,
            $stripePriceId,
            $status,
            $cancelAtPeriodEnd ? 1 : 0,
            $currentPeriodStart?->format('Y-m-d H:i:s'),
            $currentPeriodEnd?->format('Y-m-d H:i:s'),
            $canceledAt?->format('Y-m-d H:i:s'),
        ]);

        $sub = $this->findByStripeId($stripeSubscriptionId);
        if ($sub === null) {
            throw new RuntimeException("Failed to upsert subscription {$stripeSubscriptionId}");
        }
        return $sub;
    }

    private static function toDto(array $row): Subscription
    {
        return new Subscription(
            id: (int) $row['id'],
            customerId: (int) $row['customer_id'],
            stripeSubscriptionId: (string) $row['stripe_subscription_id'],
            stripePriceId: (string) $row['stripe_price_id'],
            status: (string) $row['status'],
            cancelAtPeriodEnd: (bool) $row['cancel_at_period_end'],
            currentPeriodStart: self::toDate($row['current_period_start']),
            currentPeriodEnd: self::toDate($row['current_period_end']),
            canceledAt: self::toDate($row['canceled_at']),
        );
    }

    private static function toDate(?string $s): ?DateTimeImmutable
    {
        return ($s === null || $s === '') ? null : new DateTimeImmutable($s);
    }
}
