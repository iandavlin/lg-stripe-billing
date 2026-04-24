<?php

declare(strict_types=1);

namespace LGSB\Core;

use DateTimeImmutable;
use LGSB\Bridge\WpRoleSync;
use LGSB\Contracts\IdempotencyStore;
use LGSB\Contracts\SettingsStore;
use LGSB\Domain\Repositories\ProductRepository;
use LGSB\Domain\Repositories\SubscriptionRepository;
use LGSB\Stripe\StripeGateway;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class WebhookHandler
{
    public function __construct(
        private readonly SettingsStore           $settings,
        private readonly IdempotencyStore        $idempotency,
        private readonly StripeGateway           $stripe,
        private readonly CustomerManager         $customers,
        private readonly SubscriptionRepository  $subscriptions,
        private readonly EntitlementManager      $entitlements,
        private readonly ProductRepository       $products,
        private readonly WpRoleSync              $wpSync,
        private readonly LoggerInterface         $logger = new NullLogger(),
    ) {}

    /**
     * @return array{status:string, result:string}
     */
    public function handle(string $payload, string $signature): array
    {
        try {
            $event = $this->stripe->constructWebhookEvent(
                $payload,
                $signature,
                $this->settings->getWebhookSecret(),
            );
        } catch (Throwable $e) {
            $this->logger->warning('Webhook signature verification failed', ['error' => $e->getMessage()]);
            return ['status' => 'invalid_signature', 'result' => $e->getMessage()];
        }

        $eventId = (string) ($event->id ?? '');
        if ($eventId === '') {
            return ['status' => 'invalid_event', 'result' => 'missing event id'];
        }
        if ($this->idempotency->hasProcessed($eventId)) {
            return ['status' => 'already_processed', 'result' => $eventId];
        }
        $this->idempotency->markProcessed($eventId);

        $type   = (string) ($event->type ?? '');
        $object = $event->data->object ?? null;

        $result = match ($type) {
            'checkout.session.completed'    => $this->onCheckoutCompleted($object),
            'invoice.paid'                  => $this->onInvoicePaid($object),
            'customer.subscription.updated' => $this->onSubscriptionUpdated($object),
            'customer.subscription.deleted' => $this->onSubscriptionDeleted($object),
            'invoice.payment_failed'        => $this->onPaymentFailed($object),
            default                         => "Unhandled event type: {$type}",
        };

        $this->logger->info('Webhook processed', ['event_id' => $eventId, 'type' => $type, 'result' => $result]);
        return ['status' => 'ok', 'result' => $result];
    }

    /* ------------------------------------------------------------------ */
    /* Event handlers                                                     */
    /* ------------------------------------------------------------------ */

    private function onCheckoutCompleted(?object $session): string
    {
        if (($session->mode ?? '') !== 'subscription') {
            return 'Skipped — not a subscription checkout.';
        }

        $stripeCustomerId = (string) ($session->customer ?? '');
        $subId            = (string) ($session->subscription ?? '');
        $email            = (string) ($session->customer_details->email ?? $session->customer_email ?? '');
        $name             = trim((string) ($session->customer_details->name ?? ''));
        $country          = $session->customer_details->address->country ?? null;

        if ($stripeCustomerId === '' || $subId === '' || $email === '') {
            return 'Missing required fields (customer / subscription / email).';
        }

        $sub     = $this->stripe->retrieveSubscription($subId, ['items.data.price']);
        $priceId = (string) ($sub->items->data[0]->price->id ?? '');
        $tier    = $priceId !== '' ? $this->products->tierForPrice($priceId) : null;

        if ($tier === null) {
            return "No tier mapping for price {$priceId}.";
        }

        $customer = $this->customers->findOrCreate($email, $stripeCustomerId, $name ?: null, $country);

        $subscription = $this->subscriptions->upsert(
            $customer->id,
            $subId,
            $priceId,
            (string) ($sub->status ?? ''),
            (bool) ($sub->cancel_at_period_end ?? false),
            self::tsToDate($sub->current_period_start ?? null),
            self::tsToDate($sub->current_period_end ?? null),
            self::tsToDate($sub->canceled_at ?? null),
        );

        $this->entitlements->grantMembershipFromSubscription($customer->id, $tier, $subscription->id);
        $this->wpSync->sync($customer->id);

        return "checkout.session.completed — customer {$customer->id} → {$tier}";
    }

    private function onInvoicePaid(?object $invoice): string
    {
        $stripeCustomerId = (string) ($invoice->customer ?? '');
        $subId            = (string) ($invoice->subscription ?? '');
        if ($stripeCustomerId === '' || $subId === '') {
            return 'Missing customer or subscription.';
        }

        $customer = $this->customers->findByStripeCustomerId($stripeCustomerId);
        if ($customer === null) {
            return "No customer for {$stripeCustomerId} (may arrive before checkout completes).";
        }

        $sub     = $this->stripe->retrieveSubscription($subId, ['items.data.price']);
        $priceId = (string) ($sub->items->data[0]->price->id ?? '');
        $tier    = $priceId !== '' ? $this->products->tierForPrice($priceId) : null;
        if ($tier === null) {
            return "No tier mapping for price {$priceId}.";
        }

        $subscription = $this->subscriptions->upsert(
            $customer->id,
            $subId,
            $priceId,
            (string) ($sub->status ?? ''),
            (bool) ($sub->cancel_at_period_end ?? false),
            self::tsToDate($sub->current_period_start ?? null),
            self::tsToDate($sub->current_period_end ?? null),
            self::tsToDate($sub->canceled_at ?? null),
        );

        $this->entitlements->grantMembershipFromSubscription($customer->id, $tier, $subscription->id);
        $this->wpSync->sync($customer->id);

        return "invoice.paid — customer {$customer->id} → {$tier}";
    }

    private function onSubscriptionUpdated(?object $sub): string
    {
        $stripeCustomerId = (string) ($sub->customer ?? '');
        $subId            = (string) ($sub->id ?? '');
        if ($stripeCustomerId === '' || $subId === '') {
            return 'Missing customer or subscription id.';
        }

        $customer = $this->customers->findByStripeCustomerId($stripeCustomerId);
        if ($customer === null) {
            return "No customer for {$stripeCustomerId}.";
        }

        $status  = (string) ($sub->status ?? '');
        $priceId = (string) ($sub->items->data[0]->price->id ?? '');
        $tier    = $priceId !== '' ? $this->products->tierForPrice($priceId) : null;

        $subscription = $this->subscriptions->upsert(
            $customer->id,
            $subId,
            $priceId,
            $status,
            (bool) ($sub->cancel_at_period_end ?? false),
            self::tsToDate($sub->current_period_start ?? null),
            self::tsToDate($sub->current_period_end ?? null),
            self::tsToDate($sub->canceled_at ?? null),
        );

        if ($status === 'active' && $tier !== null) {
            $this->entitlements->grantMembershipFromSubscription($customer->id, $tier, $subscription->id);
            $this->wpSync->sync($customer->id);
            return "subscription.updated — customer {$customer->id} → {$tier}";
        }

        // Paused / incomplete / other statuses keep access; cancel-at-period-end
        // is not a revocation — Stripe will fire subscription.deleted when the
        // period actually ends.
        return "subscription.updated — status={$status}, no grant change.";
    }

    private function onSubscriptionDeleted(?object $sub): string
    {
        $stripeCustomerId = (string) ($sub->customer ?? '');
        $subId            = (string) ($sub->id ?? '');
        if ($stripeCustomerId === '' || $subId === '') {
            return 'Missing customer or subscription id.';
        }

        $customer = $this->customers->findByStripeCustomerId($stripeCustomerId);
        if ($customer === null) {
            return "No customer for {$stripeCustomerId}.";
        }

        $subscription = $this->subscriptions->findByStripeId($subId);
        if ($subscription !== null) {
            $this->subscriptions->upsert(
                $customer->id,
                $subId,
                $subscription->stripePriceId,
                'canceled',
                false,
                $subscription->currentPeriodStart,
                $subscription->currentPeriodEnd,
                new DateTimeImmutable(),
            );
            $this->entitlements->revokeForSubscription($subscription->id);
        }

        $this->wpSync->sync($customer->id);
        return "subscription.deleted — customer {$customer->id} revoked.";
    }

    private function onPaymentFailed(?object $invoice): string
    {
        $stripeCustomerId = (string) ($invoice->customer ?? '');
        $customer = $stripeCustomerId !== ''
            ? $this->customers->findByStripeCustomerId($stripeCustomerId)
            : null;

        $this->logger->warning('Payment failed', [
            'stripe_customer_id' => $stripeCustomerId,
            'customer_id'        => $customer?->id,
        ]);

        // Intentionally no role/entitlement change — past_due still has access
        // until the subscription actually ends. Tagging / notification can hook
        // the `lgsm_tag_user` analogue later.
        return "invoice.payment_failed — customer={$stripeCustomerId}";
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private static function tsToDate(mixed $ts): ?DateTimeImmutable
    {
        if ($ts === null || $ts === '' || $ts === 0) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('U', (string) (int) $ts);
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }
}
