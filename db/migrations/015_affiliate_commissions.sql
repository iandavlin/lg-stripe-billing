-- Commission rates per affiliate
ALTER TABLE affiliates
    ADD COLUMN commission_pct         DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER label,
    ADD COLUMN commission_pct_annual  DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER commission_pct,
    ADD COLUMN retention_bonus_pct    DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER commission_pct_annual;

-- Store Stripe customer ID directly for easy retention polling
ALTER TABLE affiliate_conversions
    ADD COLUMN stripe_customer_id VARCHAR(64) NOT NULL DEFAULT '' AFTER affiliate_id,
    ADD COLUMN retention_bonus_eligible_at DATETIME NULL AFTER converted_at;
