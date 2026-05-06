-- Migration 012: banned_emails — independent of customers table
-- Lets admins permanently ban an email regardless of whether a customer
-- row exists today. Checked on every customer create + every gift redeem.
-- Independent from customers.blocked_at because nuking a customer wipes
-- their row, but the ban needs to survive across nuke (so the email can't
-- come back fresh and sign up again).

CREATE TABLE banned_emails (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255)    NOT NULL UNIQUE,
    reason        VARCHAR(255)        NULL,
    banned_by_wp  BIGINT UNSIGNED     NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_banned_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
