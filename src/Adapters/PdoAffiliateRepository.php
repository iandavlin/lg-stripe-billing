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
                    a.commission_pct, a.commission_pct_annual, a.retention_bonus_pct,
                    COUNT(DISTINCT cl.id)                                    AS clicks,
                    COUNT(DISTINCT cv.id)                                    AS conversions,
                    COUNT(DISTINCT CASE WHEN cv.retention_bonus_eligible_at IS NOT NULL
                                        THEN cv.id END)                      AS retention_eligible,
                    COALESCE(SUM(DISTINCT db.amount_cents), 0)               AS total_debits_cents
             FROM affiliates a
             LEFT JOIN affiliate_clicks      cl ON cl.affiliate_id = a.id
             LEFT JOIN affiliate_conversions cv ON cv.affiliate_id = a.id
             LEFT JOIN affiliate_debits      db ON db.affiliate_id = a.id
             GROUP BY a.id
             ORDER BY a.created_at DESC'
        )->fetchAll();
    }

    public function findByWpUserId(int $wpUserId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, COUNT(DISTINCT cl.id) AS clicks, COUNT(DISTINCT cv.id) AS conversions,
                    COALESCE(SUM(db.amount_cents), 0) AS total_debits_cents
             FROM affiliates a
             LEFT JOIN affiliate_clicks      cl ON cl.affiliate_id = a.id
             LEFT JOIN affiliate_conversions cv ON cv.affiliate_id = a.id
             LEFT JOIN affiliate_debits      db ON db.affiliate_id = a.id
             WHERE a.wp_user_id = ?
             GROUP BY a.id LIMIT 1'
        );
        $stmt->execute([$wpUserId]);
        return $stmt->fetch() ?: null;
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

    public function updateCommission(int $id, float $commissionPct, float $commissionPctAnnual, float $retentionBonusPct): void
    {
        $this->pdo->prepare(
            'UPDATE affiliates SET commission_pct = ?, commission_pct_annual = ?, retention_bonus_pct = ? WHERE id = ?'
        )->execute([$commissionPct, $commissionPctAnnual, $retentionBonusPct, $id]);
    }

    public function recordClick(string $slug): void
    {
        try {
            $aff = $this->findBySlug($slug);
            if ($aff === null) {
                return;
            }
            $this->pdo->prepare(
                'INSERT INTO affiliate_clicks (affiliate_id) VALUES (?)'
            )->execute([(int) $aff['id']]);
        } catch (Throwable $e) {
            error_log('LGSB affiliate record click error: ' . $e->getMessage());
        }
    }

    public function recordConversion(string $slug, int $customerId, string $stripeCustomerId, string $stripeSessionId, string $tier): void
    {
        try {
            $aff = $this->findBySlug($slug);
            if ($aff === null) {
                return;
            }
            $this->pdo->prepare(
                'INSERT IGNORE INTO affiliate_conversions
                    (affiliate_id, stripe_customer_id, customer_id, stripe_session_id, tier)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([(int) $aff['id'], $stripeCustomerId, $customerId, $stripeSessionId, $tier]);
        } catch (Throwable $e) {
            error_log('LGSB affiliate record conversion error: ' . $e->getMessage());
        }
    }

    public function conversionsForAffiliate(int $affiliateId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ac.id, ac.stripe_customer_id, ac.customer_id, ac.stripe_session_id,
                    ac.tier, ac.converted_at, ac.retention_bonus_eligible_at,
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

    public function retentionCandidates(): array
    {
        return $this->pdo->query(
            'SELECT ac.id, ac.stripe_customer_id, ac.tier, ac.converted_at,
                    a.slug, a.label, a.retention_bonus_pct,
                    c.email
             FROM affiliate_conversions ac
             JOIN affiliates a ON a.id = ac.affiliate_id
             LEFT JOIN customers c ON c.id = ac.customer_id
             WHERE ac.converted_at <= NOW() - INTERVAL 1 YEAR
               AND ac.retention_bonus_eligible_at IS NULL
               AND ac.stripe_customer_id != \'\'
               AND a.retention_bonus_pct > 0'
        )->fetchAll();
    }

    public function markRetentionEligible(int $conversionId): void
    {
        $this->pdo->prepare(
            'UPDATE affiliate_conversions SET retention_bonus_eligible_at = NOW() WHERE id = ?'
        )->execute([$conversionId]);
    }
}
