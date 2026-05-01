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
     * POST /v1/checkout — body: { price_id, quantity?, email?, country?, promo_code?, gift? }
     *
     * Intent dispatch:
     *   gift=true  (any qty>=1)                   → gift session (one-time per seat, codes generated)
     *   gift=false + regional price (region_tag)  → setup-mode session for billing verification
     *   gift=false + recurring standard price     → subscription session
     *   gift=false + one_time membership          → one-time membership session
     *
     * Backwards compatibility: if `gift` is omitted, qty>=2 is still treated
     * as gift intent (the legacy heuristic). New clients should send `gift`
     * explicitly to avoid ambiguity around qty=1 gifts.
     */
    public function create(Request $request, Response $response): Response
    {
        $body      = (array) $request->getParsedBody();
        $priceId   = trim((string) ($body['price_id']   ?? ''));
        $email     = trim((string) ($body['email']      ?? ''));
        $name      = trim((string) ($body['name']       ?? ''));
        $country   = trim((string) ($body['country']    ?? ''));
        $promoCode = trim((string) ($body['promo_code'] ?? ''));
        $quantity  = (int)        ($body['quantity']    ?? 1);
        $isGift    = array_key_exists('gift', $body)
            ? (bool) $body['gift']
            : $quantity >= 2;

        if ($priceId === '') {
            return self::json($response, ['error' => 'price_id is required'], 400);
        }
        if ($quantity < 1) {
            return self::json($response, ['error' => 'quantity must be >= 1'], 400);
        }

        $emailArg   = $email     !== '' ? $email     : null;
        $nameArg    = $name      !== '' ? $name      : null;
        $countryArg = $country   !== '' ? $country   : null;
        $promoArg   = $promoCode !== '' ? $promoCode : null;

        // Guards on existing-customer state. Gift purchases bypass these (an
        // active subscriber may still buy gifts for others).
        if (!$isGift && $emailArg !== null) {
            $existing = $this->customers->findByEmail($emailArg);
            if ($existing !== null) {
                if ($existing->isBlocked()) {
                    return self::json($response, [
                        'error' => 'This account is not eligible for new subscriptions. Please contact support if you believe this is in error.',
                    ], 403);
                }
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
            if ($isGift) {
                $result = $this->checkout->createGiftCheckoutSession(
                    $priceId, $quantity, $emailArg, $countryArg, $promoArg, $nameArg,
                );
            } else {
                $priceData = $this->products->findPriceData($priceId);
                $isRegional = $priceData !== null && $priceData['product_region_tag'] !== null;
                $isOneTime  = $priceData !== null && $priceData['interval'] === null;

                if ($isRegional) {
                    // Regional prices use setup-mode checkout for billing-country
                    // verification before any charge is made. Promo codes don't
                    // apply here (no payment in setup mode).
                    $result = $this->checkout->createRegionalSetupSession(
                        $priceId, $emailArg, $countryArg, $nameArg,
                    );
                } elseif ($isOneTime) {
                    $result = $this->checkout->createOneTimeMembershipSession(
                        $priceId, $emailArg, $countryArg, $promoArg, $nameArg,
                    );
                } else {
                    $result = $this->checkout->createSubscriptionSession(
                        $priceId, $emailArg, $countryArg, $promoArg, $nameArg,
                    );
                }
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

        // Regional verification failure returns a redirect_url so the browser
        // lands on the failure page (standard-pricing offer + support link).
        if (isset($result['redirect_url']) && is_string($result['redirect_url']) && $result['redirect_url'] !== '') {
            return $response->withStatus(302)->withHeader('Location', $result['redirect_url']);
        }

        return self::json($response, $result, $result['ok'] ? 200 : 500);
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
