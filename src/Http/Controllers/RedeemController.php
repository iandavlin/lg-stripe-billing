<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use DateTimeImmutable;
use LGSB\Core\CustomerManager;
use LGSB\Core\EntitlementManager;
use LGSB\Core\WpSync;
use LGSB\Domain\Entitlement;
use LGSB\Domain\Repositories\GiftCodeRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RedeemController
{
    public function __construct(
        private readonly GiftCodeRepository $giftCodes,
        private readonly CustomerManager    $customers,
        private readonly EntitlementManager $entitlements,
        private readonly WpSync             $wpSync,
    ) {}

    /** POST /v1/redeem — body: { code, email, name? } */
    public function redeem(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $code  = strtoupper(trim((string) ($body['code']  ?? '')));
        $email = trim((string) ($body['email'] ?? ''));
        $name  = trim((string) ($body['name']  ?? ''));

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

        $customer  = $this->customers->findOrCreate($email, null, $name ?: null, null);
        $expiresAt = new DateTimeImmutable("+{$giftCode->durationDays} days");

        $this->entitlements->grant(
            $customer->id,
            Entitlement::KIND_MEMBERSHIP_TIER,
            $giftCode->tier,
            Entitlement::SOURCE_GIFT_CODE,
            $giftCode->id,
            $expiresAt,
        );

        $this->giftCodes->redeem($giftCode->id, $customer->id);
        $this->wpSync->trigger($customer->id);

        return self::json($response, [
            'ok'          => true,
            'message'     => "Redeemed — enjoy your {$giftCode->durationDays}-day {$giftCode->tier} membership!",
            'customer_id' => $customer->id,
            'tier'        => $giftCode->tier,
            'expires_at'  => $expiresAt->format('Y-m-d'),
        ]);
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
