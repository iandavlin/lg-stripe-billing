-- Initial data for lg_membership.
-- Run after schema.sql.
--
-- Products/prices are now kept in sync via Stripe webhooks (product.*/price.*
-- events upsert into these tables automatically). This seed only bootstraps
-- the region-tag lookup table and is safe to re-run.
--
-- To add a new tier: create the product+price in the Stripe Dashboard with
-- metadata.ref = <tier-slug> and metadata.kind = membership, then trigger
-- any product.updated event (e.g. edit the description) to fire the webhook.

-- ============================================================
-- Region tags (developing-country PPP tier)
-- ============================================================

INSERT IGNORE INTO price_regions (country_code, region_tag) VALUES
    ('IN', 'DEV'), ('NG', 'DEV'), ('BD', 'DEV'), ('PK', 'DEV'),
    ('ID', 'DEV'), ('PH', 'DEV'), ('VN', 'DEV'), ('KE', 'DEV'),
    ('GH', 'DEV'), ('UG', 'DEV'), ('ET', 'DEV'), ('TZ', 'DEV'),
    ('NP', 'DEV'), ('LK', 'DEV');
