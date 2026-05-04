<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use DateTimeImmutable;
use PDO;

/**
 * Tracks Stripe Checkout sessions we have created but not yet confirmed
 * provisioned. Driven by:
 *
 *   - CheckoutService::*           → record(...) on session creation
 *   - ReturnHandler::handle()      → markResolved(...) on the happy-path
 *                                     return-URL hit
 *   - /v1/reconcile-pending sweep  → listUnresolvedOlderThan(...) followed
 *                                     by markResolved(...) once Stripe
 *                                     confirms the session is complete
 *                                     and ReturnHandler has run server-side
 *
 * Idempotent at the application layer: ReturnHandler is itself idempotent,
 * so multiple resolve attempts for the same session are safe and produce
 * the same outcome.
 */
final class PdoPendingSessionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Record a freshly-created checkout session as pending. The session_id
     * column has a UNIQUE index so duplicate calls (e.g. retry) are safe;
     * we no-op on conflict rather than overwrite, since re-recording with
     * a fresh created_at would defeat the "older than 60 seconds" filter
     * the reconcile sweep uses.
     *
     * @param string $kind 'subscription' | 'one_time' | 'gift' | 'regional_verify' | 'portal'
     */
    public function record(string $sessionId, string $kind): void
    {
        $this->pdo
            ->prepare(
                'INSERT IGNORE INTO pending_sessions (session_id, kind)
                 VALUES (?, ?)'
            )
            ->execute([$sessionId, $kind]);
    }

    /**
     * Mark a session as resolved by the happy path (return URL ran) or the
     * cron sweep (server-side reconcile). Either way the session is done
     * being watched; the row is kept for audit until pruning.
     */
    public function markResolved(string $sessionId, string $resolution): void
    {
        $this->pdo
            ->prepare(
                'UPDATE pending_sessions
                    SET resolved_at = NOW(), resolution = ?, last_polled_at = NOW()
                  WHERE session_id = ? AND resolved_at IS NULL'
            )
            ->execute([$resolution, $sessionId]);
    }

    /**
     * Touch last_polled_at without resolving. Used when the reconcile sweep
     * finds the session still open on Stripe — we don't want to spin on it
     * every tick, so future passes can skip recently-polled rows.
     */
    public function touchPolled(string $sessionId): void
    {
        $this->pdo
            ->prepare(
                'UPDATE pending_sessions SET last_polled_at = NOW() WHERE session_id = ?'
            )
            ->execute([$sessionId]);
    }

    /**
     * Unresolved rows older than the given seconds, oldest first. The sweep
     * uses 60 seconds as the floor — any newer than that, the browser is
     * almost certainly still mid-redirect and we'd be racing /v1/return.
     *
     * @return list<array{session_id:string, kind:string, created_at:string}>
     */
    public function listUnresolvedOlderThan(int $seconds): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT session_id, kind, created_at
               FROM pending_sessions
              WHERE resolved_at IS NULL
                AND created_at <= NOW() - INTERVAL ? SECOND
              ORDER BY created_at ASC
              LIMIT 100'
        );
        $stmt->execute([$seconds]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows === false ? [] : $rows;
    }

    /**
     * Hard-delete rows older than the given days regardless of resolution.
     * Pure housekeeping — keep the table small for the index.
     */
    public function pruneOlderThan(int $days): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM pending_sessions WHERE created_at < NOW() - INTERVAL ? DAY'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
