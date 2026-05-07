-- No schema change needed: retention_bonus_pct on affiliates covers this.
-- The poller queries Stripe for actual invoices paid in the first year
-- and applies retention_bonus_pct to that real total.
SELECT 1;
