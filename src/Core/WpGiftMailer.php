<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Contracts\SettingsStore;
use LGSB\Domain\GiftCode;
use Psr\Log\LoggerInterface;

/**
 * Delegates gift code email to the WP plugin's /send-gift-codes endpoint,
 * which handles FluentCRM contact creation and mail delivery.
 *
 * Best-effort: short timeout, errors logged but not raised. If the call fails,
 * the codes are still in the DB and an admin can resend manually.
 */
final class WpGiftMailer
{
    public function __construct(
        private readonly SettingsStore   $settings,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param GiftCode[] $codes */
    public function sendGiftCodes(string $toEmail, string $toName, array $codes): void
    {
        if ($codes === []) {
            return;
        }

        $url    = $this->settings->getGiftMailUrl();
        $secret = $this->settings->getSyncSharedSecret();
        if ($url === '' || $secret === '') {
            $this->logger->warning('WpGiftMailer skipped: gift_mail_url or shared_secret missing from settings');
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

        if ($payload === false) {
            $this->logger->error('WpGiftMailer json_encode failed', [
                'to_email'   => $toEmail,
                'json_error' => json_last_error_msg(),
            ]);
            return;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $this->logger->error('WpGiftMailer curl_init failed', ['url' => $url]);
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
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            $this->logger->error('WpGiftMailer endpoint call failed — codes are in DB but no email was sent', [
                'to_email'    => $toEmail,
                'code_count'  => count($codes),
                'http_status' => $status,
                'curl_error'  => $error ?: null,
                'response'    => is_string($response) ? substr($response, 0, 500) : null,
            ]);
        }
    }
}
