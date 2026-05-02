<?php

declare(strict_types=1);

namespace LGSB\Contracts;

/**
 * Slim's view of plugin configuration.
 *
 * Trimmed to only what the user-facing API needs. Tier/price/region
 * state lives in the database (ProductRepository); webhook secrets,
 * mail config, and CRM tagging live in the polling WP plugin.
 */
interface SettingsStore
{
    public function getSecretKey(): string;

    public function getPublishableKey(): string;

    public function getCheckoutReturnUrl(): string;

    public function getHomeUrl(): string;

    /** URL of the WP plugin's sync-customer REST endpoint. Empty string = disabled. */
    public function getSyncEndpointUrl(): string;

    /** URL of the WP plugin's send-gift-codes REST endpoint. Empty string = disabled. */
    public function getGiftMailUrl(): string;

    /** Shared secret for the X-LGMS-Token header. Empty string = disabled. */
    public function getSyncSharedSecret(): string;

    /** Stripe webhook signing secret (whsec_…). Empty string = signature check skipped. */
    public function getWebhookSecret(): string;

    /**
     * Bulk discount tiers parsed from BULK_DISCOUNT_TIERS env var ("10:10,20:20,50:30").
     * Sorted descending by min_qty. Empty array = no bulk discounts.
     *
     * @return array<array{min:int,pct:int}>
     */
    public function getBulkDiscountTiers(): array;

    /**
     * URL of the WP page shown when a regional billing-country check fails.
     * Sourced from APP_REGIONAL_FAIL_URL; falls back to getHomeUrl() if unset.
     * The return handler appends query params: reason=region_mismatch&region_tag=...
     */
    public function getRegionalFailUrl(): string;

    /**
     * URL of the WP page shown after a successful checkout completion.
     * Sourced from APP_RETURN_SUCCESS_URL; falls back to getHomeUrl() if unset.
     * The return handler appends query params: kind=subscription|gift|...&tier=...
     */
    public function getReturnSuccessUrl(): string;

}
