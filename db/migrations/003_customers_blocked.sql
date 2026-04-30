-- Migration 003: customers can be blocked from future billing actions
-- Used by admins (via the WP plugin's user profile UI) to prevent
-- abusive/refunded customers from re-subscribing or redeeming gifts.
-- Existing entitlements are NOT touched here -- cancel/refund those separately.

ALTER TABLE customers
    ADD COLUMN blocked_at   DATETIME    NULL AFTER deleted_at,
    ADD COLUMN block_reason VARCHAR(255) NULL AFTER blocked_at;
