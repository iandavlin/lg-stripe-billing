-- Track gift codes voided by refund. A voided code cannot be redeemed.
-- Set when the original Stripe charge for the gift purchase is refunded.

ALTER TABLE gift_codes
    ADD COLUMN voided_at DATETIME NULL AFTER redeemed_at,
    ADD KEY idx_voided (voided_at);
