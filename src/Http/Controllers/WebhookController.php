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
use Throwable;

final class WebhookController
{
    public function __construct(
        private readonly StripeGateway              $stripe,
        private readonly SettingsStore              $settings,
        private readonly ProductSyncHandler         $sync,
        private readonly SubscriptionWebhookHandler $subscriptions,
        private readonly ReturnHandler              $returns,
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
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
