-- Migration 006: gift_codes recipient fields
--
-- Tier 1 of the gift-management feature: each gift code can carry a
-- recipient (name + email + optional personal message) so we can email
-- the recipient directly with their own code, instead of dumping all
-- codes on the buyer for them to forward.
--
-- All four columns are nullable. When recipient_email is NULL, the
-- code is in the legacy "buyer-keeps-the-code" mode and gets included
-- in the bulk summary email to the buyer (as today). When set, the WP
-- plugin's gift-recipient REST endpoint sends a personalized HTML
-- email and stamps email_sent_at on success.

ALTER TABLE gift_codes
    ADD COLUMN recipient_email VARCHAR(255) NULL AFTER purchased_by,
    ADD COLUMN recipient_name  VARCHAR(255) NULL AFTER recipient_email,
    ADD COLUMN gift_message    TEXT         NULL AFTER recipient_name,
    ADD COLUMN email_sent_at   DATETIME     NULL AFTER gift_message,
    ADD KEY idx_recipient_email (recipient_email);
