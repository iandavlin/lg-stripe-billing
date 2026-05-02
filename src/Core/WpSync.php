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
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-LGMS-Token: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => json_encode(['customer_id' => $customerId]),
        ]);
        // See WpGiftMailer::resolveToLoopback — Cloudflare's bot challenge
        // intercepts internal server-to-server PHP-curl calls, so we pin
        // resolution to 127.0.0.1 to hit origin nginx directly.
        $parts = parse_url($url);
        $host  = $parts['host'] ?? '';
        if ($host !== '') {
            $scheme = $parts['scheme'] ?? 'https';
            $port   = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:127.0.0.1"]);
        }
        @curl_exec($ch);
        curl_close($ch);
    }
}
