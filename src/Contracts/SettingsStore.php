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
}
