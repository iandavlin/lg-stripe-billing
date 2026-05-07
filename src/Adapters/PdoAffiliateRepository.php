<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Domain\Repositories\AffiliateRepository;
use PDO;
use Throwable;

final class PdoAffiliateRepository implements AffiliateRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM affiliates WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public function listWithCounts(): array
    {
        return $this->pdo->query(
            'SELECT a.id, a.slug, a.label, a.created_at,
                    COUNT(c.id) AS conversions
             FROM affiliates a
             LEFT JOIN affiliate_conversions c ON c.affiliate_id = a.id
             GROUP BY a.id
             ORDER BY a.created_at DESC'
        )->fetchAll();
    }

    public function create(string $slug, string $label): array
    {
        $this->pdo->prepare(
            'INSERT INTO affiliates (slug, label) VALUES (?, ?)'
        )->execute([$slug, $label]);
        $id   = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM affiliates WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    }

    public function recordConversion(string $slug, int $customerId, string $stripeSessionId, string $tier): void
    {
        try {
            $aff = $this->findBySlug($slug);
            if ($aff === null) {
                return;
            }
            $this->pdo->prepare(
                'INSERT IGNORE INTO affiliate_conversions
                    (affiliate_id, customer_id, stripe_session_id, tier)
                 VALUES (?, ?, ?, ?)'
            )->execute([(int) $aff['id'], $customerId, $stripeSessionId, $tier]);
        } catch (Throwable $e) {
            error_log('LGSB affiliate record conversion error: ' . $e->getMessage());
        }
    }

    public function conversionsForAffiliate(int $affiliateId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ac.id, ac.customer_id, ac.stripe_session_id, ac.tier, ac.converted_at,
                    c.email
             FROM affiliate_conversions ac
             LEFT JOIN customers c ON c.id = ac.customer_id
             WHERE ac.affiliate_id = ?
             ORDER BY ac.converted_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$affiliateId]);
        return $stmt->fetchAll();
    }
}
