<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Contracts\SettingsStore;
use LGSB\Domain\GiftCode;

/**
 * Delegates gift code email to the WP plugin's /send-gift-codes endpoint,
 * which handles FluentCRM contact creation and mail delivery.
 *
 * Best-effort: short timeout, errors swallowed. If the call fails, the codes
 * are still in the DB and an admin can resend manually.
 */
final class WpGiftMailer
{
    public function __construct(private readonly SettingsStore $settings) {}

    /** @param GiftCode[] $codes */
    public function sendGiftCodes(string $toEmail, string $toName, array $codes): void
    {
        if ($codes === []) {
            return;
        }

        $url    = $this->settings->getGiftMailUrl();
        $secret = $this->settings->getSyncSharedSecret();
        if ($url === '' || $secret === '') {
            return;
        }

        $payload = json_encode([
            'to_email' => $toEmail,
            'to_name'  => $toName,
            'codes'    => array_map(
                static fn (GiftCode $c): array => [
                    'code'          => $c->code,
                    'tier'          => $c->tier,
                    'duration_days' => $c->durationDays,
                ],
                $codes,
            ),
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-LGMS-Token: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }
}
