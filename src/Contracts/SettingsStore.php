<?php

declare(strict_types=1);

namespace LGSB\Contracts;

interface SettingsStore
{
    public function getSecretKey(): string;

    public function getPublishableKey(): string;

    public function getWebhookSecret(): string;

    public function getMode(): string;

    public function getRoleForPrice(string $priceId): ?string;

    /** @return array<string,string> price_id => role */
    public function getTierMap(): array;

    public function getDevPriceId(string $priceId): ?string;

    /** @return array<int,array<string,mixed>> */
    public function getTiersForFrontend(): array;

    /** @return string[] ISO country codes */
    public function getDevelopingCountries(): array;

    public function isDevelopingCountry(string $countryCode): bool;

    public function getAdminEmail(): string;

    public function getSiteName(): string;

    public function getHomeUrl(): string;

    public function getCheckoutReturnUrl(): string;
}
