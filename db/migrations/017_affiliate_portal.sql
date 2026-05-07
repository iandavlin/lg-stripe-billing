-- Link a WP user to their affiliate account
ALTER TABLE affiliates
    ADD COLUMN wp_user_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER label,
    ADD UNIQUE KEY uq_wp_user (wp_user_id);

-- Track refunds as debits against affiliate commissions
CREATE TABLE IF NOT EXISTS affiliate_debits (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id     INT UNSIGNED     NOT NULL,
    conversion_id    INT UNSIGNED     NULL,
    stripe_charge_id VARCHAR(120)     NOT NULL,
    amount_cents     INT UNSIGNED     NOT NULL DEFAULT 0,
    debited_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_charge (stripe_charge_id),
    KEY idx_affiliate (affiliate_id),
    KEY idx_conversion (conversion_id)
);
