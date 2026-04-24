<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Contracts\SettingsStore;

final class EnvSettingsStore implements SettingsStore
{
    public function getSecretKey(): string
    {
        return self::env('STRIPE_SECRET_KEY');
    }

    public function getPublishableKey(): string
    {
        return self::env('STRIPE_PUBLISHABLE_KEY');
    }

    public function getCheckoutReturnUrl(): string
    {
        $base = rtrim(self::env('APP_BASE_URL'), '/');
        return $base . '/v1/return?session_id={CHECKOUT_SESSION_ID}';
    }

    public function getHomeUrl(): string
    {
        return self::env('APP_HOME_URL');
    }

    private static function env(string $key): string
    {
        $v = $_ENV[$key] ?? getenv($key);
        return is_string($v) ? $v : '';
    }
}
