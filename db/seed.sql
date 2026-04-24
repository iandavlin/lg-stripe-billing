-- Initial data for lg_membership.
-- Run after schema.sql.
--
-- Seeds the one membership tier currently configured in the WP plugin.
-- Add more rows here when new Stripe products/prices are created.

-- ============================================================
-- Membership tiers (products)
-- ============================================================

INSERT INTO products (stripe_product_id, kind, ref, name, active) VALUES
    ('prod_looth2_test', 'membership', 'looth2', 'Looth Test (looth2)', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), active = VALUES(active);

-- ============================================================
-- Prices
-- Default price (no region tag) for the one configured test tier.
-- Add per-region prices later by inserting more rows with region_tag set.
-- ============================================================

INSERT INTO prices
    (product_id, stripe_price_id, type, `interval`, unit_amount_cents, currency, region_tag, priority, active)
SELECT
    p.id,
    'price_1QlXoLHg6gcIV22bj141Eoke',
    'recurring',
    'month',
    500,    -- ADJUST to actual price-cents from Stripe
    'usd',
    NULL,   -- default-region fallback
    100,
    1
FROM products p
WHERE p.ref = 'looth2'
ON DUPLICATE KEY UPDATE active = VALUES(active);

-- ============================================================
-- Region tags (developing-country examples; expand later)
-- ============================================================

INSERT IGNORE INTO price_regions (country_code, region_tag) VALUES
    -- DEV: standard developing-country tier (placeholder list — expand in prod)
    ('IN', 'DEV'), ('NG', 'DEV'), ('BD', 'DEV'), ('PK', 'DEV'),
    ('ID', 'DEV'), ('PH', 'DEV'), ('VN', 'DEV'), ('KE', 'DEV'),
    ('GH', 'DEV'), ('UG', 'DEV'), ('ET', 'DEV'), ('TZ', 'DEV'),
    ('NP', 'DEV'), ('LK', 'DEV');
