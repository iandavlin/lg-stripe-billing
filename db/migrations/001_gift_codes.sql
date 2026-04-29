-- Migration 001: gift_codes table
-- Gift codes are generated when a bulk/gift checkout completes.
-- Each code grants one standalone membership entitlement on redemption.

CREATE TABLE gift_codes (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code              CHAR(12)        NOT NULL,
    tier              VARCHAR(64)     NOT NULL,
    duration_days     INT UNSIGNED    NOT NULL,
    purchased_by      BIGINT UNSIGNED NOT NULL,
    redeemed_by       BIGINT UNSIGNED NULL,
    stripe_session_id VARCHAR(128)    NULL,
    redeemed_at       DATETIME        NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_code        (code),
    KEY        idx_purchased  (purchased_by),
    KEY        idx_redeemed   (redeemed_by),
    CONSTRAINT fk_gc_purchased FOREIGN KEY (purchased_by) REFERENCES customers(id),
    CONSTRAINT fk_gc_redeemed  FOREIGN KEY (redeemed_by)  REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
