<?php

declare(strict_types=1);

namespace LGSB\Core;

use DateInterval;
use DateTimeImmutable;
use LGSB\Domain\Entitlement;
use LGSB\Domain\GiftCode;
use LGSB\Domain\Repositories\EntitlementRepository;
use LGSB\Domain\Repositories\GiftCodeRepository;
use LGSB\Domain\Repositories\ProductRepository;

/**
 * Encapsulates gift code redemption logic, including conflict resolution
 * when a redeemer already has active gift entitlement(s).
 *
 * Conflict cases:
 *   1. No active gift entitlements        → grant directly
 *   2. Same tier as incoming              → silent extend (push existing expiry)
 *   3. Different tier, no strategy chosen → return choice options to caller
 *   4. Different tier, strategy chosen    → apply the strategy
 *
 * Stripe-subscription entitlements are not touched by any path here.
 * Gifts always create their own entitlement rows; if a user has an active
 * Stripe sub and redeems a gift, the gift may temporarily upgrade their
 * tier (via the arbiter), and the sub re-asserts when the gift expires.
 */
final class GiftRedemptionService
{
    public const STRATEGY_STACK_HIGHER_FIRST = 'stack_higher_first';
    public const STRATEGY_STACK_LOWER_FIRST  = 'stack_lower_first';
    public const STRATEGY_PRORATE_TO_HIGHER  = 'prorate_to_higher';
    public const STRATEGY_PRORATE_TO_LOWER   = 'prorate_to_lower';

    public function __construct(
        private readonly EntitlementRepository $entitlements,
        private readonly GiftCodeRepository    $giftCodes,
        private readonly ProductRepository     $products,
        private readonly WpSync                $wpSync,
    ) {}

    /**
     * Apply a gift code for a customer.
     *
     * @return array — see redeem-result shapes below
     */
    public function redeem(int $customerId, GiftCode $giftCode, ?string $strategy = null): array
    {
        $now           = new DateTimeImmutable();
        $activeGifts   = $this->entitlements->activeGiftsForCustomer($customerId, $now);
        $existingTier  = $this->highestTier($activeGifts);
        $existingDays  = $this->sumRemainingDays($activeGifts, $now);
        $incomingTier  = $giftCode->tier;
        $incomingDays  = $giftCode->durationDays;

        // Case 1: no active gift state — grant directly.
        if ($existingTier === null) {
            $expiresAt = $now->add(new DateInterval('P' . $incomingDays . 'D'));
            $this->grantGift($customerId, $incomingTier, $giftCode->id, $now, $expiresAt);
            $this->giftCodes->redeem($giftCode->id, $customerId);
            $this->wpSync->trigger($customerId);
            return $this->successResult($customerId, $incomingTier, $expiresAt, "Redeemed — enjoy your {$incomingDays}-day {$incomingTier} membership!");
        }

        // Case 2: same tier — silent extend.
        if ($existingTier === $incomingTier) {
            $totalDays = $existingDays + $incomingDays;
            $this->revokeAndConsolidate($customerId, $activeGifts, $incomingTier, $totalDays, $giftCode->id, $now);
            $this->giftCodes->redeem($giftCode->id, $customerId);
            $this->wpSync->trigger($customerId);
            $expiresAt = $now->add(new DateInterval('P' . $totalDays . 'D'));
            return $this->successResult($customerId, $incomingTier, $expiresAt, "Extended — your {$incomingTier} membership now runs for {$totalDays} days.");
        }

        // Case 3: different tier — need strategy.
        $tierHigher = $this->higherTier($existingTier, $incomingTier);
        $tierLower  = $tierHigher === $existingTier ? $incomingTier : $existingTier;
        $daysHigher = $tierHigher === $existingTier ? $existingDays : $incomingDays;
        $daysLower  = $tierLower  === $existingTier ? $existingDays : $incomingDays;

        if ($strategy === null) {
            return $this->buildChoiceResult($existingTier, $existingDays, $incomingTier, $incomingDays, $tierHigher, $tierLower, $daysHigher, $daysLower);
        }

        // Case 4: apply strategy.
        return $this->applyStrategy(
            $strategy,
            $customerId,
            $giftCode,
            $activeGifts,
            $tierHigher, $tierLower,
            $daysHigher, $daysLower,
            $now,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Strategy application                                               */
    /* ------------------------------------------------------------------ */

    private function applyStrategy(
        string             $strategy,
        int                $customerId,
        GiftCode           $giftCode,
        array              $activeGifts,
        string             $tierHigher,
        string             $tierLower,
        int                $daysHigher,
        int                $daysLower,
        DateTimeImmutable  $now,
    ): array {
        $convertedDays = $this->convertedDays($tierHigher, $tierLower, $daysHigher, $daysLower);
        // $convertedDays['lower_to_higher'] = lower-tier days re-expressed in higher-tier days
        // $convertedDays['higher_to_lower'] = higher-tier days re-expressed in lower-tier days

        // Revoke all existing gift entitlements; we'll write fresh rows.
        foreach ($activeGifts as $e) {
            $this->entitlements->revoke($e->id);
        }

        switch ($strategy) {
            case self::STRATEGY_STACK_HIGHER_FIRST: {
                // Higher tier active first; lower starts when higher ends.
                $higherStart = $now;
                $higherEnd   = $now->add(new DateInterval('P' . $daysHigher . 'D'));
                $lowerStart  = $higherEnd;
                $lowerEnd    = $lowerStart->add(new DateInterval('P' . $daysLower . 'D'));
                $this->grantGift($customerId, $tierHigher, $giftCode->id, $higherStart, $higherEnd);
                $this->grantGift($customerId, $tierLower,  $giftCode->id, $lowerStart,  $lowerEnd);
                $msg = sprintf(
                    '%s for %d days, then %s for %d days (total %d).',
                    $tierHigher, $daysHigher, $tierLower, $daysLower, $daysHigher + $daysLower,
                );
                $finalExpires = $lowerEnd;
                break;
            }
            case self::STRATEGY_STACK_LOWER_FIRST: {
                $lowerStart  = $now;
                $lowerEnd    = $now->add(new DateInterval('P' . $daysLower . 'D'));
                $higherStart = $lowerEnd;
                $higherEnd   = $higherStart->add(new DateInterval('P' . $daysHigher . 'D'));
                $this->grantGift($customerId, $tierLower,  $giftCode->id, $lowerStart,  $lowerEnd);
                $this->grantGift($customerId, $tierHigher, $giftCode->id, $higherStart, $higherEnd);
                $msg = sprintf(
                    '%s for %d days, then %s for %d days (total %d).',
                    $tierLower, $daysLower, $tierHigher, $daysHigher, $daysHigher + $daysLower,
                );
                $finalExpires = $higherEnd;
                break;
            }
            case self::STRATEGY_PRORATE_TO_HIGHER: {
                $totalDays = $daysHigher + $convertedDays['lower_to_higher'];
                $expires   = $now->add(new DateInterval('P' . $totalDays . 'D'));
                $this->grantGift($customerId, $tierHigher, $giftCode->id, $now, $expires);
                $msg = sprintf('%s for %d days (prorated).', $tierHigher, $totalDays);
                $finalExpires = $expires;
                break;
            }
            case self::STRATEGY_PRORATE_TO_LOWER: {
                $totalDays = $daysLower + $convertedDays['higher_to_lower'];
                $expires   = $now->add(new DateInterval('P' . $totalDays . 'D'));
                $this->grantGift($customerId, $tierLower, $giftCode->id, $now, $expires);
                $msg = sprintf('%s for %d days (prorated).', $tierLower, $totalDays);
                $finalExpires = $expires;
                break;
            }
            default:
                return ['ok' => false, 'error' => "Unknown strategy: {$strategy}"];
        }

        $this->giftCodes->redeem($giftCode->id, $customerId);
        $this->wpSync->trigger($customerId);

        // For strategies producing two rows, "current tier" is whichever is
        // active right now (always the first row's tier). For prorate
        // strategies, it's the single tier created.
        $currentTier = $strategy === self::STRATEGY_STACK_LOWER_FIRST ? $tierLower
                     : ($strategy === self::STRATEGY_PRORATE_TO_LOWER ? $tierLower : $tierHigher);

        return $this->successResult($customerId, $currentTier, $finalExpires, $msg);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** @param Entitlement[] $entitlements */
    private function highestTier(array $entitlements): ?string
    {
        $tiers = array_values(array_filter(
            array_map(static fn (Entitlement $e) => $e->kind === Entitlement::KIND_MEMBERSHIP_TIER ? $e->ref : null, $entitlements),
            static fn ($v) => $v !== null,
        ));
        if ($tiers === []) {
            return null;
        }
        usort($tiers, static fn (string $a, string $b): int => strcmp($b, $a));
        return $tiers[0];
    }

    /** @param Entitlement[] $entitlements */
    private function sumRemainingDays(array $entitlements, DateTimeImmutable $now): int
    {
        $totalSeconds = 0;
        foreach ($entitlements as $e) {
            if ($e->expiresAt === null) {
                continue;
            }
            $end = $e->expiresAt->getTimestamp();
            $totalSeconds += max(0, $end - $now->getTimestamp());
        }
        return (int) ceil($totalSeconds / 86400);
    }

    private function higherTier(string $a, string $b): string
    {
        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    /**
     * @return array{lower_to_higher:int, higher_to_lower:int}
     */
    private function convertedDays(string $tierHigher, string $tierLower, int $daysHigher, int $daysLower): array
    {
        $higherCents = $this->products->pricePerYearCentsForTier($tierHigher);
        $lowerCents  = $this->products->pricePerYearCentsForTier($tierLower);
        if ($higherCents === null || $lowerCents === null || $higherCents <= 0 || $lowerCents <= 0) {
            // Fallback: treat as 1:1 if pricing not available. Caller should ideally
            // disable prorate options when this happens, but we don't want to crash.
            return ['lower_to_higher' => $daysLower, 'higher_to_lower' => $daysHigher];
        }
        // lower→higher: a lower-tier day is worth (lowerCents/higherCents) higher-tier days
        $lowerToHigher = (int) floor($daysLower * $lowerCents / $higherCents);
        // higher→lower: a higher-tier day is worth (higherCents/lowerCents) lower-tier days
        $higherToLower = (int) floor($daysHigher * $higherCents / $lowerCents);
        return ['lower_to_higher' => $lowerToHigher, 'higher_to_lower' => $higherToLower];
    }

    private function buildChoiceResult(
        string $existingTier, int $existingDays,
        string $incomingTier, int $incomingDays,
        string $tierHigher,   string $tierLower,
        int    $daysHigher,   int    $daysLower,
    ): array {
        $converted = $this->convertedDays($tierHigher, $tierLower, $daysHigher, $daysLower);

        return [
            'ok'              => true,
            'requires_choice' => true,
            'current'         => ['tier' => $existingTier, 'days_remaining' => $existingDays],
            'incoming'        => ['tier' => $incomingTier, 'duration_days'  => $incomingDays],
            'options'         => [
                [
                    'id'    => self::STRATEGY_STACK_HIGHER_FIRST,
                    'label' => "{$tierHigher} for {$daysHigher} days, then {$tierLower} for {$daysLower} days",
                    'tier_first'  => $tierHigher,
                    'days_first'  => $daysHigher,
                    'tier_second' => $tierLower,
                    'days_second' => $daysLower,
                    'total_days'  => $daysHigher + $daysLower,
                ],
                [
                    'id'    => self::STRATEGY_STACK_LOWER_FIRST,
                    'label' => "{$tierLower} for {$daysLower} days, then {$tierHigher} for {$daysHigher} days",
                    'tier_first'  => $tierLower,
                    'days_first'  => $daysLower,
                    'tier_second' => $tierHigher,
                    'days_second' => $daysHigher,
                    'total_days'  => $daysHigher + $daysLower,
                ],
                [
                    'id'    => self::STRATEGY_PRORATE_TO_HIGHER,
                    'label' => "All {$tierHigher} — " . ($daysHigher + $converted['lower_to_higher']) . ' days',
                    'tier'       => $tierHigher,
                    'total_days' => $daysHigher + $converted['lower_to_higher'],
                ],
                [
                    'id'    => self::STRATEGY_PRORATE_TO_LOWER,
                    'label' => "All {$tierLower} — " . ($daysLower + $converted['higher_to_lower']) . ' days',
                    'tier'       => $tierLower,
                    'total_days' => $daysLower + $converted['higher_to_lower'],
                ],
            ],
            'recommended' => self::STRATEGY_PRORATE_TO_HIGHER,
        ];
    }

    /**
     * Revoke active gift entitlements and write a single consolidated row at
     * the given tier with the given total days. Used for same-tier extend.
     */
    private function revokeAndConsolidate(
        int               $customerId,
        array             $activeGifts,
        string            $tier,
        int               $totalDays,
        int               $giftCodeId,
        DateTimeImmutable $now,
    ): void {
        foreach ($activeGifts as $e) {
            $this->entitlements->revoke($e->id);
        }
        $expires = $now->add(new DateInterval('P' . $totalDays . 'D'));
        $this->grantGift($customerId, $tier, $giftCodeId, $now, $expires);
    }

    private function grantGift(
        int               $customerId,
        string            $tier,
        int               $giftCodeId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $expiresAt,
    ): Entitlement {
        return $this->entitlements->grant(
            $customerId,
            Entitlement::KIND_MEMBERSHIP_TIER,
            $tier,
            Entitlement::SOURCE_GIFT_CODE,
            $giftCodeId,
            $expiresAt,
            $startsAt,
        );
    }

    private function successResult(int $customerId, string $tier, DateTimeImmutable $expiresAt, string $message): array
    {
        return [
            'ok'          => true,
            'message'     => $message,
            'customer_id' => $customerId,
            'tier'        => $tier,
            'expires_at'  => $expiresAt->format('Y-m-d'),
        ];
    }
}
