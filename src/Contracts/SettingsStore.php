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

    /** Shared secret for the X-LGMS-Token header. Empty string = disabled. */
    public function getSyncSharedSecret(): string;

    /** Stripe webhook signing secret (whsec_…). Empty string = signature check skipped. */
    public function getWebhookSecret(): string;
}
