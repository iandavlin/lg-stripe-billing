-- Affiliate link tracking: slugs → conversions (one row per paid session).
-- UNIQUE on stripe_session_id provides idempotency across webhook/return/reconcile.

CREATE TABLE IF NOT EXISTS affiliates (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug       VARCHAR(80)  NOT NULL,
    label      VARCHAR(160) NOT NULL DEFAULT '',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS affiliate_conversions (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    affiliate_id      INT UNSIGNED NOT NULL,
    customer_id       INT UNSIGNED NOT NULL,
    stripe_session_id VARCHAR(120) NOT NULL,
    tier              VARCHAR(40)  NOT NULL DEFAULT '',
    converted_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session   (stripe_session_id),
    KEY        idx_affiliate (affiliate_id),
    KEY        idx_customer  (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
