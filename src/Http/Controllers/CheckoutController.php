<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use InvalidArgumentException;
use LGSB\Core\CheckoutService;
use LGSB\Core\CustomerManager;
use LGSB\Core\ReturnHandler;
use LGSB\Domain\Repositories\ProductRepository;
use LGSB\Domain\Repositories\SubscriptionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CheckoutController
{
    public function __construct(
        private readonly CheckoutService        $checkout,
        private readonly ReturnHandler          $returnHandler,
        private readonly CustomerManager        $customers,
        private readonly ProductRepository      $products,
        private readonly SubscriptionRepository $subscriptions,
    ) {}

    /**
     * POST /v1/checkout — body: { price_id, quantity?, email?, country?, promo_code? }
     *
     * Routes the request based on quantity and price type:
     *   quantity >= 2                          → gift session (one-time, qty seats)
     *   quantity = 1 + recurring price         → subscription session
     *   quantity = 1 + one_time membership price → one-time membership session
     */
    public function create(Request $request, Response $response): Response
    {
        $body      = (array) $request->getParsedBody();
        $priceId   = trim((string) ($body['price_id']   ?? ''));
        $email     = trim((string) ($body['email']      ?? ''));
        $country   = trim((string) ($body['country']    ?? ''));
        $promoCode = trim((string) ($body['promo_code'] ?? ''));
        $quantity  = (int)        ($body['quantity']    ?? 1);

        if ($priceId === '') {
            return self::json($response, ['error' => 'price_id is required'], 400);
        }
        if ($quantity < 1) {
            return self::json($response, ['error' => 'quantity must be >= 1'], 400);
        }

        $emailArg   = $email     !== '' ? $email     : null;
        $countryArg = $country   !== '' ? $country   : null;
        $promoArg   = $promoCode !== '' ? $promoCode : null;

        // Guard: a customer with an active subscription should manage it via
        // the Stripe Customer Portal, not start a parallel one. Only blocks
        // sub + one-time membership flows; gift purchases (qty>=2) are
        // independent and can stack on top of an active sub.
        if ($quantity === 1 && $emailArg !== null) {
            $existing = $this->customers->findByEmail($emailArg);
            if ($existing !== null) {
                $activeSubs = $this->subscriptions->findActiveForCustomer($existing->id);
                if ($activeSubs !== []) {
                    return self::json($response, [
                        'error'          => 'You already have an active subscription. Manage your plan from your account to upgrade, downgrade, or cancel — starting a second subscription would bill you twice.',
                        'has_active_sub' => true,
                    ], 409);
                }
            }
        }

        try {
            if ($quantity >= 2) {
                $result = $this->checkout->createGiftCheckoutSession(
                    $priceId, $quantity, $emailArg, $countryArg, $promoArg,
                );
            } else {
                $priceData = $this->products->findPriceData($priceId);
                $isOneTime = $priceData !== null && $priceData['interval'] === null;
                $result = $isOneTime
                    ? $this->checkout->createOneTimeMembershipSession(
                        $priceId, $emailArg, $countryArg, $promoArg,
                    )
                    : $this->checkout->createSubscriptionSession(
                        $priceId, $emailArg, $countryArg, $promoArg,
                    );
            }
        } catch (InvalidArgumentException $e) {
            return self::json($response, ['error' => $e->getMessage()], 400);
        }

        return self::json($response, $result);
    }

    /** POST /v1/portal — body: { email } */
    public function portal(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));

        if ($email === '') {
            return self::json($response, ['error' => 'email is required'], 400);
        }

        $customer = $this->customers->findByEmail($email);
        if ($customer === null) {
            return self::json($response, ['error' => 'No customer for that email.'], 404);
        }

        try {
            $result = $this->checkout->createPortalSession($customer->id);
        } catch (InvalidArgumentException $e) {
            return self::json($response, ['error' => $e->getMessage()], 400);
        }

        return self::json($response, $result);
    }

    /** GET /v1/return?session_id=... — Stripe redirect handler */
    public function handleReturn(Request $request, Response $response): Response
    {
        $sessionId = (string) ($request->getQueryParams()['session_id'] ?? '');
        if ($sessionId === '') {
            return self::json($response, ['error' => 'session_id is required'], 400);
        }

        $result = $this->returnHandler->handle($sessionId);
        return self::json($response, $result, $result['ok'] ? 200 : 500);
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
