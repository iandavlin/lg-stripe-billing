<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use LGSB\Core\CustomerManager;
use LGSB\Core\GiftRedemptionService;
use LGSB\Domain\Repositories\GiftCodeRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RedeemController
{
    public function __construct(
        private readonly GiftCodeRepository    $giftCodes,
        private readonly CustomerManager       $customers,
        private readonly GiftRedemptionService $service,
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
        if ($giftCode->isRedeemed()) {
            return self::json($response, ['error' => 'Code has already been redeemed.'], 409);
        }

        $customer = $this->customers->findOrCreate($email, null, $name ?: null, null);
        $result   = $this->service->redeem($customer->id, $giftCode, $strategy);

        return self::json($response, $result);
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
