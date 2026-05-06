<?php

declare(strict_types=1);

namespace LGSB\Domain\Repositories;

use LGSB\Domain\GiftCode;

interface GiftCodeRepository
{
    public function findById(int $id): ?GiftCode;

    public function findByCode(string $code): ?GiftCode;

    /** @return GiftCode[] All codes minted for a Stripe session (excludes voided). */
    public function findByStripeSessionId(string $stripeSessionId): array;

    /**
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

    /** Stamp email_sent_at only when NULL (preserves original send time). */
    public function markEmailSent(int $giftCodeId): void;

    /** Overwrite email_sent_at unconditionally — used by Resend. */
    public function stampEmailSentAt(int $giftCodeId): void;

    /**
     * Update recipient fields on one code. Does NOT touch email_sent_at.
     */
    public function updateRecipient(
        int     $giftCodeId,
        string  $recipientEmail,
        ?string $recipientName,
        ?string $giftMessage,
    ): void;

    /**
     * Void one code. Only acts when voided_at IS NULL AND redeemed_at IS NULL.
     * Returns true when the row was actually updated.
     */
    public function voidById(int $giftCodeId): bool;

    public function redeem(int $giftCodeId, int $redeemedBy): void;

    /** @return array{voided:int[], already_redeemed:int[]} */
    public function voidByStripeSessionId(string $stripeSessionId): array;
}
