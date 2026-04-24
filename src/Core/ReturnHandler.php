<?php

declare(strict_types=1);

namespace LGSB\Core;

use DateTimeImmutable;
use LGSB\Domain\Repositories\ProductRepository;
use LGSB\Domain\Repositories\SubscriptionRepository;
use LGSB\Stripe\StripeGateway;

/**
 * Handles the synchronous return URL after Stripe Checkout.
 *
 * Provisions customer + subscription + entitlement immediately so the
 * user feels instant activation. The polling WP plugin catches up
 * any other state changes in the background.
 */
class ReturnHandler
{
    public function __construct(
        private readonly StripeGateway          $stripe,
        private readonly ProductRepository      $products,
        private readonly CustomerManager        $customers,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntitlementManager     $entitlements,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     customer_id?: int,
     *     tier?: string,
     * }
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

        if (($session->mode ?? '') !== 'subscription') {
            return ['ok' => false, 'message' => 'Not a subscription checkout.'];
        }

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

        return [
            'ok'          => true,
            'message'     => "Provisioned {$customer->email} → {$tier}",
            'customer_id' => $customer->id,
            'tier'        => $tier,
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
