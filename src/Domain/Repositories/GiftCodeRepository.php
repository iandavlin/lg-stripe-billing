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
     * @return GiftCode[]
     */
    public function createBatch(
        int    $count,
        int    $purchasedBy,
        string $tier,
        int    $durationDays,
        string $stripeSessionId,
    ): array;

    public function redeem(int $giftCodeId, int $redeemedBy): void;
}
