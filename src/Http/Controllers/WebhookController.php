<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use LGSB\Contracts\SettingsStore;
use LGSB\Core\ProductSyncHandler;
use LGSB\Core\SubscriptionWebhookHandler;
use LGSB\Stripe\StripeGateway;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Stripe\Exception\SignatureVerificationException;

final class WebhookController
{
    public function __construct(
        private readonly StripeGateway              $stripe,
        private readonly SettingsStore              $settings,
        private readonly ProductSyncHandler         $sync,
        private readonly SubscriptionWebhookHandler $subscriptions,
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
            default                                                        => null,
        };

        return self::json($response, ['ok' => true]);
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
