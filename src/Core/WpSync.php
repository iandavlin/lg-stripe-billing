<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Contracts\SettingsStore;

final class WpSync
{
    public function __construct(private readonly SettingsStore $settings) {}

    /**
     * POST customer_id to the WP plugin's sync-customer endpoint.
     * Best-effort: short timeout, errors swallowed. The plugin's cron is the safety net.
     */
    public function trigger(int $customerId): void
    {
        $url    = $this->settings->getSyncEndpointUrl();
        $secret = $this->settings->getSyncSharedSecret();
        if ($url === '' || $secret === '') {
            return;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-LGMS-Token: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => json_encode(['customer_id' => $customerId]),
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }
}
