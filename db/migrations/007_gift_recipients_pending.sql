-- Migration 007: gift_recipients_pending
--
-- Buyer's recipient list captured on POST /v1/checkout, keyed by the
-- Stripe Checkout session ID, consumed by ReturnHandler::handleGift on
-- the corresponding /v1/return. Lifetimes are short (minutes between
-- checkout intent and completion) so we don't worry about TTL/sweep —
-- if a session is abandoned, rows just sit forever (negligible volume).
--
-- Stored separately from gift_codes because gift_codes only get rows
-- AFTER the session completes and we know the actual generated code
-- values. We need the recipient data BEFORE that, surviving across the
-- Stripe redirect chain. Stripe metadata can't fit a 50-recipient
-- batch reliably (8KB total cap), hence this table.

CREATE TABLE gift_recipients_pending (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- 128 chars: newer Stripe Checkout session IDs can run >64 chars; the
    -- legacy 64-char limit on existing tables (orders.stripe_checkout_session_id,
    -- gift_codes.stripe_session_id) survives only because those values were
    -- captured pre-format-change.
    stripe_checkout_session_id  VARCHAR(128) NOT NULL,
    position                    INT UNSIGNED NOT NULL,
    recipient_email             VARCHAR(255) NULL,
    recipient_name              VARCHAR(255) NULL,
    gift_message                TEXT         NULL,
    created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_session_position (stripe_checkout_session_id, position),
    KEY idx_session (stripe_checkout_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
