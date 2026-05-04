-- pending_sessions: Stripe Checkout sessions we have created but not yet
-- confirmed as provisioned. Inserted on /v1/checkout, marked resolved on
-- successful /v1/return. The cron-driven /v1/reconcile-pending sweep polls
-- Stripe for any row older than ~60 seconds that is still unresolved and
-- runs ReturnHandler::handle() server-side, recovering charges where the
-- browser never made it back to /v1/return (modal closed, browser crash,
-- network drop, etc.).
--
-- Idempotency: ReturnHandler is idempotent at the entitlement layer; the
-- reconcile sweep just guarantees it runs at least once per completed
-- session.

CREATE TABLE IF NOT EXISTS pending_sessions (
    id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id      VARCHAR(255)        NOT NULL,
    kind            VARCHAR(32)         NOT NULL,
    created_at      DATETIME            NOT NULL DEFAULT current_timestamp(),
    resolved_at     DATETIME            NULL,
    resolution      VARCHAR(32)         NULL,
    last_polled_at  DATETIME            NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_session_id (session_id),
    KEY idx_unresolved (resolved_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
