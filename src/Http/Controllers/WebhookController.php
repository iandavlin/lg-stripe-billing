<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use LGSB\Contracts\SettingsStore;
use LGSB\Core\ProductSyncHandler;
use LGSB\Core\ReturnHandler;
use LGSB\Core\SubscriptionWebhookHandler;
use LGSB\Stripe\StripeGateway;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Stripe\Exception\SignatureVerificationException;
use PDO;
use Throwable;

final class WebhookController
{
    public function __construct(
        private readonly StripeGateway              $stripe,
        private readonly SettingsStore              $settings,
        private readonly ProductSyncHandler         $sync,
        private readonly SubscriptionWebhookHandler $subscriptions,
        private readonly ReturnHandler              $returns,
        private readonly PDO                        $pdo,
    ) {}

    /** POST /v1/webhook */
    public function handle(Request $request, Response $response): Response
    {
        $payload   = (string) $request->getBody();
        $sigHeader = $request->getHeaderLine('Stripe-Signature');
        $secret    = $this->settings->getWebhookSecret();

        if ($secret === '') {
            return self::json($response, ['error' => 'Webhook secret not configured.'], 500);
        }

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException) {
            return self::json($response, ['error' => 'Invalid signature.'], 400);
        }

        $obj = $event->data->object;

        match ($event->type) {
            'product.created',              'product.updated'              => $this->sync->handleProductEvent($obj),
            'price.created',                'price.updated'                => $this->sync->handlePriceEvent($obj),
            'customer.subscription.updated','customer.subscription.deleted' => $this->subscriptions->handle($obj),
            'checkout.session.completed'                                   => $this->handleCheckoutCompleted($obj),
            'charge.refunded'                                              => $this->handleChargeRefunded($obj),
            default                                                        => null,
        };

        return self::json($response, ['ok' => true]);
    }

    /**
     * Fast-path provisioning for any completed Checkout Session.
     *
     * The browser-side /v1/return is fragile (modal close, network drop,
     * crash), and the cron-driven /v1/reconcile-pending sweep recovers
     * within ~5 minutes worst case. This webhook handler closes that
     * window further: Stripe pushes us this event server-to-server within
     * seconds of payment completion, so the typical orphan recovery time
     * drops from minutes to seconds.
     *
     * Stripe documents this as the recommended fulfillment trigger; see:
     * https://docs.stripe.com/checkout/fulfillment
     *
     * Idempotency: ReturnHandler::handle is idempotent at the entitlement
     * layer, so a webhook + a /v1/return both completing for the same
     * session is safe — the second call is a no-op. The pending_sessions
     * row is also marked resolved by ReturnHandler, so the polling sweep
     * will skip whatever the webhook already handled.
     *
     * Errors are swallowed so this endpoint always returns 200 to Stripe.
     * Stripe will otherwise retry with exponential backoff up to ~3 days,
     * which would mask the real cause; we want errors visible in our log
     * and recoverable on the next polling sweep.
     */
    private function handleCheckoutCompleted(object $session): void
    {
        $sessionId = (string) ($session->id ?? '');
        if ($sessionId === '') {
            error_log('LGSB webhook: checkout.session.completed missing session id');
            return;
        }

        try {
            $result = $this->returns->handle($sessionId);
            if (!($result['ok'] ?? false)) {
                error_log("LGSB webhook recovery for {$sessionId}: " . ((string) ($result['message'] ?? 'unknown')));
            }
        } catch (Throwable $e) {
            error_log("LGSB webhook recovery for {$sessionId} threw: " . $e->getMessage());
        }

        // Trial abuse guard: one trial per physical card (fingerprint).
        // Post-hoc: we can only check after checkout because we don't know
        // the card until Stripe's UI completes.
        if (($session->mode ?? '') === 'subscription') {
            $this->enforceTrialFingerprint($session);
        }
    }

    /**
     * When a charge is refunded, find the affiliate whose conversion
     * brought in this customer and record the refund as a debit.
     * Silently swallowed — Stripe must always get 200.
     */
    private function handleChargeRefunded(object $charge): void
    {
        try {
            $stripeCustomerId = (string) ($charge->customer ?? '');
            $chargeId         = (string) ($charge->id ?? '');
            $refundedCents    = (int)   ($charge->amount_refunded ?? 0);

            if ($stripeCustomerId === '' || $chargeId === '' || $refundedCents <= 0) {
                return;
            }

            // Find the affiliate conversion for this customer.
            $stmt = $this->pdo->prepare(
                'SELECT ac.id, ac.affiliate_id
                 FROM affiliate_conversions ac
                 WHERE ac.stripe_customer_id = ?
                 ORDER BY ac.converted_at DESC LIMIT 1'
            );
            $stmt->execute([$stripeCustomerId]);
            $conversion = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$conversion) {
                return; // not an affiliate-referred customer
            }

            $this->pdo->prepare(
                'INSERT IGNORE INTO affiliate_debits
                    (affiliate_id, conversion_id, stripe_charge_id, amount_cents)
                 VALUES (?, ?, ?, ?)'
            )->execute([
                (int) $conversion['affiliate_id'],
                (int) $conversion['id'],
                $chargeId,
                $refundedCents,
            ]);
        } catch (Throwable $e) {
            error_log('LGSB affiliate refund debit error: ' . $e->getMessage());
        }
    }

    private function enforceTrialFingerprint(object $session): void
    {
        try {
            $subId = (string) ($session->subscription ?? '');
            if ($subId === '') {
                return;
            }

            $sub = $this->stripe->retrieveSubscription($subId, ['default_payment_method']);
            if (($sub->status ?? '') !== 'trialing') {
                return;
            }

            $pmId = (string) ($sub->default_payment_method->id ?? $sub->default_payment_method ?? '');
            if ($pmId === '') {
                return;
            }

            $pm          = $this->stripe->retrievePaymentMethod($pmId);
            $fingerprint = (string) ($pm->card->fingerprint ?? '');
            if ($fingerprint === '') {
                return; // non-card PM (SEPA, etc.) — allow trial
            }

            // Try to claim this fingerprint atomically.
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO trial_fingerprints (fingerprint) VALUES (?)'
            );
            $stmt->execute([$fingerprint]);

            if ($stmt->rowCount() === 0) {
                // Fingerprint already used — end the trial immediately.
                $this->stripe->updateSubscription($subId, ['trial_end' => 'now']);
                error_log("LGSB trial-guard: ended trial on {$subId} — fingerprint {$fingerprint} already used");
            }
        } catch (Throwable $e) {
            error_log('LGSB trial-guard error: ' . $e->getMessage());
        }
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
