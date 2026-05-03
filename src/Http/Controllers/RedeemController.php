<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use LGSB\Core\CheckoutService;
use LGSB\Core\CustomerManager;
use LGSB\Core\GiftRedemptionService;
use LGSB\Domain\Repositories\GiftCodeRepository;
use LGSB\Domain\Repositories\SubscriptionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RedeemController
{
    public function __construct(
        private readonly GiftCodeRepository     $giftCodes,
        private readonly CustomerManager        $customers,
        private readonly GiftRedemptionService  $service,
        private readonly SubscriptionRepository $subscriptions,
        private readonly CheckoutService        $checkout,
    ) {}

    /**
     * POST /v1/redeem — body: { code, email, name?, strategy? }
     *
     * If a tier conflict exists and no strategy is provided, the response
     * contains `requires_choice: true` plus an `options` array; the client
     * re-submits with one of the option ids in `strategy`.
     */
    public function redeem(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $code     = strtoupper(trim((string) ($body['code']  ?? '')));
        $email    = trim((string) ($body['email'] ?? ''));
        $name     = trim((string) ($body['name']  ?? ''));
        $strategy = trim((string) ($body['strategy'] ?? '')) ?: null;

        if ($code === '') {
            return self::json($response, ['error' => 'code is required.'], 400);
        }
        if ($email === '') {
            return self::json($response, ['error' => 'email is required.'], 400);
        }

        $giftCode = $this->giftCodes->findByCode($code);
        if ($giftCode === null) {
            return self::json($response, ['error' => 'Invalid code.'], 404);
        }
        if ($giftCode->isVoided()) {
            return self::json($response, ['error' => 'This code has been refunded and is no longer valid.'], 410);
        }
        if ($giftCode->isRedeemed()) {
            return self::json($response, ['error' => 'Code has already been redeemed.'], 409);
        }

        // Email is stapled to the code: when the sender directed the gift
        // to a specific recipient, that email is the only one this code can
        // redeem under. Overrides whatever the form posted (frontend already
        // marks the field readonly, this is defense-in-depth against
        // DevTools tampering or non-browser clients).
        if (!empty($giftCode->recipientEmail)) {
            $email = (string) $giftCode->recipientEmail;
        }

        $customer = $this->customers->findOrCreate($email, null, $name ?: null, null);

        if ($customer->isBlocked()) {
            return self::json($response, ['error' => 'This account is not eligible to redeem gift codes. Please contact support if you believe this is in error.'], 403);
        }

        if ($this->subscriptions->findActiveForCustomer($customer->id) !== []) {
            $payload = ['error' => 'Gift codes are for new members. Your account already has an active subscription — to use a gift code, cancel your subscription and redeem once it expires.'];
            try {
                $portal = $this->checkout->createPortalSession($customer->id);
                $payload['portal_url'] = $portal['url'];
            } catch (\Throwable) {
                // No Stripe ID on record — omit portal link rather than crash.
            }
            return self::json($response, $payload, 409);
        }

        $result   = $this->service->redeem($customer->id, $giftCode, $strategy);

        return self::json($response, $result);
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
