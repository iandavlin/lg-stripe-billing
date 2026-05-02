<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

use LGSB\Domain\GiftCode;

interface GiftCodeRepository
{
    public function findByCode(string $code): ?GiftCode;

    /**
     * Generate $count unique codes and insert them in one batch.
     *
     * Optional $recipients is a list of associative arrays, one per code
     * (in order). Each entry may contain {email, name, message} — any of
     * which may be null/empty. When set, the code is marked for direct-
     * to-recipient emailing; when null, the code is part of the legacy
     * "buyer keeps the code" mode.
     *
     * @param list<array{email?:?string, name?:?string, message?:?string}>|null $recipients
     * @return GiftCode[]
     */
    public function createBatch(
        int    $count,
        int    $purchasedBy,
        string $tier,
        int    $durationDays,
        string $stripeSessionId,
        ?array $recipients = null,
    ): array;

    /**
     * Mark a gift code as having had its recipient email sent. Used by the
     * WP plugin's send-gift-emails endpoint after wp_mail() succeeds so we
     * never double-send and admins can audit which codes have shipped.
     */
    public function markEmailSent(int $giftCodeId): void;

    public function redeem(int $giftCodeId, int $redeemedBy): void;

    /**
     * Void all unredeemed codes from a given Stripe Checkout session.
     * Returns IDs of any codes already redeemed (need admin review) and
     * IDs that were just voided.
     *
     * @return array{voided:int[], already_redeemed:int[]}
     */
    public function voidByStripeSessionId(string $stripeSessionId): array;
}
