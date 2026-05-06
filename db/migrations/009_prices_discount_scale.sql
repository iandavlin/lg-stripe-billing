-- Migration 009: add discount_scale to prices table
-- Applied: 2026-05-05 (session 15)
-- Purpose: gift prices with shorter durations (1/3/6 months) can carry a
-- discount_scale (0–1) that multiplies the global BULK_DISCOUNT_TIERS factor
-- so shorter gifts receive a proportionally smaller bulk discount. Defaults to
-- 1.0 (no scaling) so existing prices are unaffected.
ALTER TABLE prices
    ADD COLUMN discount_scale DECIMAL(5,4) NOT NULL DEFAULT 1.0000;
