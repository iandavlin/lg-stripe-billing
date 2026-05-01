-- Migration 005: products_region_tag
-- Move regional segmentation from the price level to the product level.
--
-- Three-tier model:
--   products.region_tag IS NULL      => standard product (visible to all)
--   products.region_tag = 'regional_a' => mid-income discount tier
--   products.region_tag = 'regional_b' => deeper discount tier
--
-- Country → region mapping stays in price_regions (unchanged table structure).
-- The old price-level low_income test data is cleaned up here.

ALTER TABLE products
    ADD COLUMN region_tag VARCHAR(16) NULL AFTER ref,
    ADD KEY idx_region_tag (region_tag);

-- Remove old low_income country mapping from dev seed (no longer used).
DELETE FROM price_regions WHERE region_tag = 'low_income';

-- Deactivate the old $2/mo low-income LITE price. Archive the Stripe price in
-- the Dashboard as well; this just prevents it from appearing in our queries.
UPDATE prices
    SET active     = 0,
        region_tag = NULL
WHERE stripe_price_id = 'price_1TS4rpHg6gcIV22bJvmKYopL';
