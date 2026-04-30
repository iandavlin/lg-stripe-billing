-- Migration 004: admin_action_log
-- Records every admin-triggered membership action (cancel, refund, block,
-- unblock) so the reason and outcome are auditable on our side, not just in
-- Stripe metadata. Written by the WP plugin's admin REST endpoints.

CREATE TABLE admin_action_log (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id    BIGINT UNSIGNED NOT NULL,
    actor_wp_user  BIGINT UNSIGNED NULL,
    action         VARCHAR(64)     NOT NULL,
    sub_id         VARCHAR(128)    NULL,
    refund_id      VARCHAR(128)    NULL,
    refund_amount  INT             NULL,
    reason         TEXT            NULL,
    success        TINYINT(1)      NOT NULL DEFAULT 1,
    error_message  TEXT            NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_customer_created (customer_id, created_at),
    CONSTRAINT fk_aal_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
