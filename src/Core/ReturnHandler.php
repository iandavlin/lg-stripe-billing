<?php

declare(strict_types=1);

namespace LGSB\Core;

use DateTimeImmutable;
use LGSB\Domain\Repositories\GiftCodeRepository;
use LGSB\Domain\Repositories\ProductRepository;
use LGSB\Domain\Repositories\SubscriptionRepository;
use LGSB\Stripe\StripeGateway;

/**
 * Handles the synchronous return URL after Stripe Checkout.
 *
 * Two paths:
 *   subscription → provision customer + subscription + entitlement immediately
 *   payment/gift  → generate N gift codes, email to purchaser
 */
class ReturnHandler
{
    public function __construct(
        private readonly StripeGateway          $stripe,
        private readonly ProductRepository      $products,
        private readonly CustomerManager        $customers,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntitlementManager     $entitlements,
        private readonly GiftCodeRepository     $giftCodes,
        private readonly WpGiftMailer           $mailer,
        private readonly WpSync                 $wpSync,
    ) {}

    /**
     * @return array{ok:bool,message:string,customer_id?:int,tier?:string,quantity?:int}
     */
    public function handle(string $sessionId): array
    {
        $session = $this->stripe->retrieveCheckoutSession($sessionId, [
            'subscription',
            'subscription.items.data.price',
        ]);

        if (($session->status ?? '') !== 'complete') {
            return [
                'ok'      => false,
                'message' => 'Session not complete: ' . ((string) ($session->status ?? 'unknown')),
            ];
        }

        $mode = (string) ($session->mode ?? '');

        if ($mode === 'payment') {
            $checkoutType = (string) ($session->metadata->checkout_type ?? '');
            return match ($checkoutType) {
                'gift'              => $this->handleGift($session),
                'membership_annual' => $this->handleOneTimeMembership($session),
                default             => ['ok' => false, 'message' => "Unknown payment checkout type: {$checkoutType}."],
            };
        }

        if ($mode !== 'subscription') {
            return ['ok' => false, 'message' => "Unhandled checkout mode: {$mode}."];
        }

        return $this->handleSubscription($session);
    }

    /**
     * Handle a one-time annual membership purchase. Customer paid for a fixed
     * duration (no Stripe subscription); we grant an entitlement with explicit
     * expires_at and source_type='order'.
     */
    private function handleOneTimeMembership(object $session): array
    {
        $meta = $session->metadata;
        $stripeCustomerId = (string) ($session->customer ?? '');
        $email            = (string) ($session->customer_details->email ?? $session->customer_email ?? '');
        $name             = trim((string) ($session->customer_details->name ?? ''));
        $country          = $session->customer_details->address->country ?? null;
        $tier             = (string) ($meta->tier ?? '');
        $durationDays     = max(1, (int) ($meta->duration_days ?? 365));

        if ($email === '' || $tier === '') {
            return ['ok' => false, 'message' => 'Membership session missing email or tier.'];
        }

        $customer = $this->customers->findOrCreate(
            $email,
            $stripeCustomerId !== '' ? $stripeCustomerId : null,
            $name ?: null,
            $country,
        );

        $expiresAt = (new DateTimeImmutable())->add(new \DateInterval("P{$durationDays}D"));

        $this->entitlements->grantMembershipFromOrder(
            $customer->id,
            $tier,
            (int) ($session->id !== null ? crc32((string) $session->id) : 0),
            $expiresAt,
        );

        $this->wpSync->trigger($customer->id);

        return [
            'ok'          => true,
            'message'     => "Provisioned {$customer->email} → {$tier} for {$durationDays} days",
            'customer_id' => $customer->id,
            'tier'        => $tier,
            'expires_at'  => $expiresAt->format('Y-m-d'),
        ];
    }

    private function handleSubscription(object $session): array
    {
        $stripeCustomerId = (string) ($session->customer ?? '');
        $email            = (string) ($session->customer_details->email ?? $session->customer_email ?? '');
        $name             = trim((string) ($session->customer_details->name ?? ''));
        $country          = $session->customer_details->address->country ?? null;

        if ($stripeCustomerId === '' || $email === '') {
            return ['ok' => false, 'message' => 'Session missing customer ID or email.'];
        }

        $sub = $session->subscription;
        if (! is_object($sub)) {
            return ['ok' => false, 'message' => 'Subscription not expanded on session.'];
        }

        $priceId = (string) ($sub->items->data[0]->price->id ?? '');
        $tier    = $priceId !== '' ? $this->products->tierForPrice($priceId) : null;

        if ($tier === null) {
            return ['ok' => false, 'message' => "No tier mapping for price {$priceId}."];
        }

        $customer = $this->customers->findOrCreate($email, $stripeCustomerId, $name ?: null, $country);

        $subscription = $this->subscriptions->upsert(
            $customer->id,
            (string) $sub->id,
            $priceId,
            (string) ($sub->status ?? ''),
            (bool) ($sub->cancel_at_period_end ?? false),
            self::tsToDate($sub->current_period_start ?? null),
            self::tsToDate($sub->current_period_end ?? null),
            self::tsToDate($sub->canceled_at ?? null),
        );

        $this->entitlements->grantMembershipFromSubscription(
            $customer->id,
            $tier,
            $subscription->id,
        );

        $this->wpSync->trigger($customer->id);

        return [
            'ok'          => true,
            'message'     => "Provisioned {$customer->email} → {$tier}",
            'customer_id' => $customer->id,
            'tier'        => $tier,
        ];
    }

    private function handleGift(object $session): array
    {
        $meta = $session->metadata;
        if (($meta->checkout_type ?? '') !== 'gift') {
            return ['ok' => false, 'message' => 'Unknown payment checkout type.'];
        }

        $email        = (string) ($session->customer_details->email ?? $session->customer_email ?? '');
        $name         = trim((string) ($session->customer_details->name ?? ''));
        $country      = $session->customer_details->address->country ?? null;
        $tier         = (string) ($meta->tier ?? '');
        $quantity     = max(1, (int) ($meta->quantity ?? 1));
        $durationDays = max(1, (int) ($meta->duration_days ?? 365));

        if ($email === '' || $tier === '') {
            return ['ok' => false, 'message' => 'Gift session missing email or tier.'];
        }

        $stripeCustomerId = (string) ($session->customer ?? '');
        $customer = $this->customers->findOrCreate(
            $email,
            $stripeCustomerId !== '' ? $stripeCustomerId : null,
            $name ?: null,
            $country,
        );

        $codes = $this->giftCodes->createBatch(
            $quantity,
            $customer->id,
            $tier,
            $durationDays,
            (string) $session->id,
        );

        $this->mailer->sendGiftCodes($email, $name ?: 'Looth Member', $codes);

        return [
            'ok'          => true,
            'message'     => "Generated {$quantity} gift code(s) for {$email}",
            'customer_id' => $customer->id,
            'tier'        => $tier,
            'quantity'    => $quantity,
        ];
    }

    private static function tsToDate(mixed $ts): ?DateTimeImmutable
    {
        if ($ts === null || $ts === '' || $ts === 0) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('U', (string) (int) $ts);
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }
}
