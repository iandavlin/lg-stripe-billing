<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Contracts\SettingsStore;
use LGSB\Domain\GiftCode;
use Psr\Log\LoggerInterface;

/**
 * Delegates gift code email to the WP plugin's REST endpoints.
 *
 * Two send paths:
 *   sendGiftCodes()     — bulk initial send after checkout (buyer summary + per-recipient)
 *   sendOneRecipient()  — single recipient email for Send/Resend/Reassign actions
 *
 * Both are best-effort: errors logged but not raised.
 */
final class WpGiftMailer
{
    public function __construct(
        private readonly SettingsStore   $settings,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param GiftCode[] $codes
     */
    public function sendGiftCodes(string $toEmail, string $toName, array $codes, bool $dashboardMode = false): void
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
            'to_email'       => $toEmail,
            'to_name'        => $toName,
            'dashboard_mode' => $dashboardMode,
            'codes'    => array_map(
                static fn (GiftCode $c): array => [
                    'id'              => $c->id,
                    'code'            => $c->code,
                    'tier'            => $c->tier,
                    'duration_days'   => $c->durationDays,
                    'recipient_email' => $c->recipientEmail,
                    'recipient_name'  => $c->recipientName,
                    'gift_message'    => $c->giftMessage,
                ],
                $codes,
            ),
        ]);

        if ($payload === false) {
            $this->logger->error('WpGiftMailer json_encode failed', ['to_email' => $toEmail, 'json_error' => json_last_error_msg()]);
            return;
        }

        $this->post($url, $secret, $payload, 'sendGiftCodes', ['to_email' => $toEmail, 'code_count' => count($codes)]);
    }

    /**
     * Send a single per-recipient email for one gift code.
     * Called by GiftActionController for Send / Resend / Reassign actions.
     * Uses the /send-gift-recipient WP endpoint which fires only the
     * recipient email (no buyer summary).
     */
    public function sendOneRecipient(GiftCode $code, string $giverEmail, string $giverName): void
    {
        $base   = $this->settings->getHomeUrl();
        $url    = rtrim($base, '/') . '/wp-json/lg-member-sync/v1/send-gift-recipient';
        $secret = $this->settings->getSyncSharedSecret();

        if ($url === '' || $secret === '') {
            $this->logger->warning('WpGiftMailer::sendOneRecipient skipped: endpoint or shared_secret missing');
            return;
        }

        $payload = json_encode([
            'giver_email' => $giverEmail,
            'giver_name'  => $giverName,
            'code'        => [
                'id'              => $code->id,
                'code'            => $code->code,
                'tier'            => $code->tier,
                'duration_days'   => $code->durationDays,
                'recipient_email' => $code->recipientEmail,
                'recipient_name'  => $code->recipientName,
                'gift_message'    => $code->giftMessage,
            ],
        ]);

        if ($payload === false) {
            $this->logger->error('WpGiftMailer::sendOneRecipient json_encode failed', ['code_id' => $code->id]);
            return;
        }

        $this->post($url, $secret, $payload, 'sendOneRecipient', ['code_id' => $code->id, 'recipient' => $code->recipientEmail]);
    }

    private function post(string $url, string $secret, string $payload, string $caller, array $logContext): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            $this->logger->error("WpGiftMailer::{$caller} curl_init failed", array_merge($logContext, ['url' => $url]));
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-LGMS-Token: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        self::resolveToLoopback($ch, $url);
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            $this->logger->error("WpGiftMailer::{$caller} endpoint call failed", array_merge($logContext, [
                'http_status' => $status,
                'curl_error'  => $error ?: null,
                'response'    => is_string($response) ? substr($response, 0, 500) : null,
            ]));
        }
    }

    private static function resolveToLoopback(\CurlHandle $ch, string $url): void
    {
        $parts = parse_url($url);
        $host  = $parts['host'] ?? '';
        if ($host === '') {
            return;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $port   = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:127.0.0.1"]);
    }
}
