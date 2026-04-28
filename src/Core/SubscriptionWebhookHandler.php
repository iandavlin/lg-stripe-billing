<?php

declare(strict_types=1);

namespace LGSB\Core;

use DateTimeImmutable;
use LGSB\Contracts\SettingsStore;
use LGSB\Domain\Repositories\CustomerRepository;
use LGSB\Domain\Repositories\ProductRepository;
use LGSB\Domain\Repositories\SubscriptionRepository;

final class SubscriptionWebhookHandler
{
    // statuses that immediately revoke access
    private const REVOKE_STATUSES = ['canceled', 'incomplete_expired'];

    public function __construct(
        private readonly CustomerRepository     $customers,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ProductRepository      $products,
        private readonly EntitlementManager     $entitlements,
        private readonly SettingsStore          $settings,
    ) {}

    /**
     * Handle customer.subscription.updated and customer.subscription.deleted.
     *
     * Policy (from PICKUP):
     *   active / trialing  → grant / re-grant entitlement
     *   past_due           → keep existing entitlement, just update subscription row
     *   canceled / incomplete_expired → revoke immediately
     */
    public function handle(object $stripeSub): void
    {
        $stripeCustomerId = (string) ($stripeSub->customer ?? '');
        if ($stripeCustomerId === '') {
            return;
        }

        $customer = $this->customers->findByStripeCustomerId($stripeCustomerId);
        if ($customer === null) {
            // Stripe customer not yet in our DB — nothing to provision.
            return;
        }

        $status  = (string) ($stripeSub->status ?? '');
        $priceId = (string) ($stripeSub->items->data[0]->price->id ?? '');

        $subscription = $this->subscriptions->upsert(
            $customer->id,
            (string) $stripeSub->id,
            $priceId,
            $status,
            (bool) ($stripeSub->cancel_at_period_end ?? false),
            self::tsToDate($stripeSub->current_period_start ?? null),
            self::tsToDate($stripeSub->current_period_end ?? null),
            self::tsToDate($stripeSub->canceled_at ?? null),
        );

        if (in_array($status, self::REVOKE_STATUSES, true)) {
            $this->entitlements->revokeForSubscription($subscription->id);
        } elseif ($status === 'active' || $status === 'trialing') {
            $tier = $priceId !== '' ? $this->products->tierForPrice($priceId) : null;
            if ($tier !== null) {
                $this->entitlements->grantMembershipFromSubscription($customer->id, $tier, $subscription->id);
            }
            // past_due: subscription row updated above; entitlement untouched (retry window policy)
        }

        $this->triggerWpSync($customer->id);
    }

    private function triggerWpSync(int $customerId): void
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
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-LGMS-Token: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => json_encode(['customer_id' => $customerId]),
        ]);
        @curl_exec($ch);
        curl_close($ch);
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
