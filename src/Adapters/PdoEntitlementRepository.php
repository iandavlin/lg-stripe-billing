<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use DateTimeImmutable;
use LGSB\Domain\Entitlement;
use LGSB\Domain\Repositories\EntitlementRepository;
use PDO;
use RuntimeException;

final class PdoEntitlementRepository implements EntitlementRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findById(int $id): ?Entitlement
    {
        $stmt = $this->pdo->prepare('SELECT * FROM entitlements WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::toDto($row) : null;
    }

    public function activeForCustomer(int $customerId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM entitlements
             WHERE customer_id = ?
               AND revoked_at IS NULL
               AND starts_at <= ?
               AND (expires_at IS NULL OR expires_at > ?)
             ORDER BY id DESC'
        );
        $stmt->execute([$customerId, $nowStr, $nowStr]);
        return array_map([self::class, 'toDto'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findBySource(string $sourceType, int $sourceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM entitlements
             WHERE source_type = ? AND source_id = ?
             ORDER BY id DESC'
        );
        $stmt->execute([$sourceType, $sourceId]);
        return array_map([self::class, 'toDto'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function grant(
        int                $customerId,
        string             $kind,
        string             $ref,
        string             $sourceType,
        ?int               $sourceId,
        ?DateTimeImmutable $expiresAt,
    ): Entitlement {
        // Idempotency: if an active entitlement already exists for the same
        // source + same ref + same kind, return it instead of writing churn.
        if ($sourceId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM entitlements
                 WHERE customer_id = ? AND kind = ? AND ref = ?
                   AND source_type = ? AND source_id = ?
                   AND revoked_at IS NULL
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$customerId, $kind, $ref, $sourceType, $sourceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return self::toDto($row);
            }
        }

        $uuid = Uuid::v4();
        $stmt = $this->pdo->prepare(
            'INSERT INTO entitlements
                (uuid, customer_id, kind, ref, source_type, source_id, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $uuid,
            $customerId,
            $kind,
            $ref,
            $sourceType,
            $sourceId,
            $expiresAt?->format('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        return $this->findById($id) ?? throw new RuntimeException('Failed to grant entitlement');
    }

    public function revoke(int $entitlementId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE entitlements SET revoked_at = NOW()
             WHERE id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$entitlementId]);
    }

    public function revokeBySource(string $sourceType, int $sourceId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE entitlements SET revoked_at = NOW()
             WHERE source_type = ? AND source_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$sourceType, $sourceId]);
    }

    private static function toDto(array $row): Entitlement
    {
        return new Entitlement(
            id: (int) $row['id'],
            uuid: (string) $row['uuid'],
            customerId: (int) $row['customer_id'],
            kind: (string) $row['kind'],
            ref: (string) $row['ref'],
            sourceType: (string) $row['source_type'],
            sourceId: $row['source_id'] !== null ? (int) $row['source_id'] : null,
            startsAt: new DateTimeImmutable((string) $row['starts_at']),
            expiresAt: $row['expires_at'] !== null ? new DateTimeImmutable((string) $row['expires_at']) : null,
            revokedAt: $row['revoked_at'] !== null ? new DateTimeImmutable((string) $row['revoked_at']) : null,
        );
    }
}
