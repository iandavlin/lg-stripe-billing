<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use InvalidArgumentException;
use LGSB\Core\CheckoutService;
use LGSB\Core\CustomerManager;
use LGSB\Core\ReturnHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CheckoutController
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly ReturnHandler   $returnHandler,
        private readonly CustomerManager $customers,
    ) {}

    /** POST /v1/checkout  — body: { price_id, email?, country? } */
    public function create(Request $request, Response $response): Response
    {
        $body    = (array) $request->getParsedBody();
        $priceId = trim((string) ($body['price_id'] ?? ''));
        $email   = trim((string) ($body['email']    ?? ''));
        $country = trim((string) ($body['country']  ?? ''));

        if ($priceId === '') {
            return self::json($response, ['error' => 'price_id is required'], 400);
        }

        try {
            $result = $this->checkout->createSubscriptionSession(
                $priceId,
                $email   !== '' ? $email   : null,
                $country !== '' ? $country : null,
            );
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
