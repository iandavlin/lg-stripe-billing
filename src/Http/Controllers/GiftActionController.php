<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use LGSB\Contracts\SettingsStore;
use LGSB\Core\WpGiftMailer;
use LGSB\Domain\Repositories\CustomerRepository;
use LGSB\Domain\Repositories\EntitlementRepository;
use LGSB\Domain\Repositories\GiftCodeRepository;
use LGSB\Domain\Repositories\SubscriptionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * POST /v1/gift-send      {code_id, recipient_email, recipient_name?, message?, buyer_email}
 * POST /v1/gift-resend    {code_id, buyer_email}
 * POST /v1/gift-reassign  {code_id, recipient_email, recipient_name?, message?, buyer_email}
 * POST /v1/gift-void      {code_id, buyer_email}
 *
 * Auth: X-LGMS-Token shared secret (server-to-server from the WP plugin).
 * buyer_email is included in the body so Slim can verify code ownership.
 */
final class GiftActionController
{
    public function __construct(
        private readonly GiftCodeRepository     $giftCodes,
        private readonly CustomerRepository     $customers,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntitlementRepository  $entitlements,
        private readonly WpGiftMailer           $mailer,
        private readonly SettingsStore          $settings,
        private readonly LoggerInterface        $logger,
    ) {}

    public function send(Request $request, Response $response): Response
    {
        if (!$this->authorized($request)) {
            return self::json($response, ['error' => 'Unauthorized'], 401);
        }

        $body       = (array) $request->getParsedBody();
        $codeId     = (int) ($body['code_id'] ?? 0);
        $toEmail    = trim((string) ($body['recipient_email'] ?? ''));
        $toName     = trim((string) ($body['recipient_name']  ?? ''));
        $message    = trim((string) ($body['message']         ?? ''));
        $buyerEmail = trim((string) ($body['buyer_email']     ?? ''));

        if ($codeId <= 0)    return self::json($response, ['error' => 'code_id is required.'], 400);
        if ($toEmail === '') return self::json($response, ['error' => 'recipient_email is required.'], 400);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return self::json($response, ['error' => 'recipient_email is not a valid email address.'], 422);
        }

        $code = $this->giftCodes->findById($codeId);
        if ($code === null)        return self::json($response, ['error' => 'Gift code not found.'], 404);
        if ($code->isRedeemed())   return self::json($response, ['error' => 'Code has already been redeemed.'], 409);
        if ($code->isVoided())     return self::json($response, ['error' => 'Code has been voided.'], 410);
        if ($code->hasRecipient()) return self::json($response, ['error' => 'Code already has a recipient. Use reassign to change it.'], 409);

        if ($err = $this->ownershipError($code->purchasedBy, $buyerEmail)) return $err($response);

        if (empty($body['acknowledged_recipient_warning'])) {
            $warning = $this->recipientWarning($toEmail);
            if ($warning !== null) {
                return self::json($response, [
                    'needs_recipient_confirmation' => true,
                    'recipient_warning'            => $warning,
                ]);
            }
        }

        $buyer = $this->customers->findById($code->purchasedBy);

        $this->giftCodes->updateRecipient($codeId, $toEmail, $toName ?: null, $message ?: null);

        // Reload so the mailer sees the fresh recipient fields.
        $fresh = $this->giftCodes->findById($codeId);
        $this->mailer->sendOneRecipient($fresh, $buyer?->email ?? $buyerEmail, $buyer?->name ?? '');

        $this->giftCodes->stampEmailSentAt($codeId);

        return self::json($response, ['ok' => true, 'code_id' => $codeId]);
    }

    public function resend(Request $request, Response $response): Response
    {
        if (!$this->authorized($request)) {
            return self::json($response, ['error' => 'Unauthorized'], 401);
        }

        $body       = (array) $request->getParsedBody();
        $codeId     = (int) ($body['code_id'] ?? 0);
        $buyerEmail = trim((string) ($body['buyer_email'] ?? ''));

        if ($codeId <= 0) return self::json($response, ['error' => 'code_id is required.'], 400);

        $code = $this->giftCodes->findById($codeId);
        if ($code === null)           return self::json($response, ['error' => 'Gift code not found.'], 404);
        if ($code->isRedeemed())      return self::json($response, ['error' => 'Code has already been redeemed.'], 409);
        if ($code->isVoided())        return self::json($response, ['error' => 'Code has been voided.'], 410);
        if (!$code->hasRecipient())   return self::json($response, ['error' => 'No recipient set. Use send first.'], 422);

        if ($err = $this->ownershipError($code->purchasedBy, $buyerEmail)) return $err($response);

        $buyer = $this->customers->findById($code->purchasedBy);
        $this->mailer->sendOneRecipient($code, $buyer?->email ?? $buyerEmail, $buyer?->name ?? '');
        $this->giftCodes->stampEmailSentAt($codeId);

        return self::json($response, ['ok' => true, 'code_id' => $codeId]);
    }

    public function reassign(Request $request, Response $response): Response
    {
        if (!$this->authorized($request)) {
            return self::json($response, ['error' => 'Unauthorized'], 401);
        }

        $body       = (array) $request->getParsedBody();
        $codeId     = (int) ($body['code_id'] ?? 0);
        $toEmail    = trim((string) ($body['recipient_email'] ?? ''));
        $toName     = trim((string) ($body['recipient_name']  ?? ''));
        $message    = trim((string) ($body['message']         ?? ''));
        $buyerEmail = trim((string) ($body['buyer_email']     ?? ''));

        if ($codeId <= 0)    return self::json($response, ['error' => 'code_id is required.'], 400);
        if ($toEmail === '') return self::json($response, ['error' => 'recipient_email is required.'], 400);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return self::json($response, ['error' => 'recipient_email is not a valid email address.'], 422);
        }

        $code = $this->giftCodes->findById($codeId);
        if ($code === null)      return self::json($response, ['error' => 'Gift code not found.'], 404);
        if ($code->isRedeemed()) return self::json($response, ['error' => 'Code has already been redeemed — cannot reassign.'], 409);
        if ($code->isVoided())   return self::json($response, ['error' => 'Code has been voided.'], 410);

        if ($err = $this->ownershipError($code->purchasedBy, $buyerEmail)) return $err($response);

        if (empty($body['acknowledged_recipient_warning'])) {
            $warning = $this->recipientWarning($toEmail);
            if ($warning !== null) {
                return self::json($response, [
                    'needs_recipient_confirmation' => true,
                    'recipient_warning'            => $warning,
                ]);
            }
        }

        $buyer = $this->customers->findById($code->purchasedBy);

        $this->giftCodes->updateRecipient($codeId, $toEmail, $toName ?: null, $message ?: null);

        $fresh = $this->giftCodes->findById($codeId);
        $this->mailer->sendOneRecipient($fresh, $buyer?->email ?? $buyerEmail, $buyer?->name ?? '');
        $this->giftCodes->stampEmailSentAt($codeId);

        return self::json($response, ['ok' => true, 'code_id' => $codeId]);
    }

    public function void(Request $request, Response $response): Response
    {
        if (!$this->authorized($request)) {
            return self::json($response, ['error' => 'Unauthorized'], 401);
        }

        $body       = (array) $request->getParsedBody();
        $codeId     = (int) ($body['code_id'] ?? 0);
        $buyerEmail = trim((string) ($body['buyer_email'] ?? ''));

        if ($codeId <= 0) return self::json($response, ['error' => 'code_id is required.'], 400);

        $code = $this->giftCodes->findById($codeId);
        if ($code === null)      return self::json($response, ['error' => 'Gift code not found.'], 404);
        if ($code->isRedeemed()) return self::json($response, ['error' => 'Code has already been redeemed — cannot void.'], 409);
        if ($code->isVoided())   return self::json($response, ['error' => 'Code is already voided.'], 410);

        if ($err = $this->ownershipError($code->purchasedBy, $buyerEmail)) return $err($response);

        $voided = $this->giftCodes->voidById($codeId);
        if (!$voided) {
            return self::json($response, ['error' => 'Could not void code (already redeemed or voided).'], 409);
        }

        return self::json($response, ['ok' => true, 'code_id' => $codeId]);
    }

    /**
     * Look up a recipient email's account state. Returns a warning payload
     * when the recipient already has either an active subscription (gift
     * would stack uselessly) or an active gift entitlement (multiple gifts
     * stack but the buyer probably wants to know). Returns null when the
     * recipient is brand new — no warning needed.
     *
     * @return array{kind:string,tier?:string,expires_at?:?string,days_remaining?:int}|null
     */
    private function recipientWarning(string $email): ?array
    {
        $existing = $this->customers->findByEmail($email);
        if ($existing === null) {
            return null;
        }

        if ($this->subscriptions->findActiveForCustomer($existing->id) !== []) {
            return ['kind' => 'subscription'];
        }

        $activeGifts = $this->entitlements->activeGiftsForCustomer($existing->id);
        if ($activeGifts !== []) {
            $top       = $activeGifts[0];
            $expiresAt = $top->expiresAt instanceof \DateTimeImmutable ? $top->expiresAt : null;
            $daysRemaining = 0;
            if ($expiresAt instanceof \DateTimeImmutable) {
                $diff = (new \DateTimeImmutable())->diff($expiresAt);
                $daysRemaining = max(0, (int) $diff->format('%r%a'));
            }
            return [
                'kind'           => 'gift',
                'tier'           => (string) $top->ref,
                'expires_at'     => $expiresAt ? $expiresAt->format('Y-m-d') : null,
                'days_remaining' => $daysRemaining,
            ];
        }

        return null;
    }

    private function authorized(Request $request): bool
    {
        $secret = $this->settings->getSyncSharedSecret();
        if ($secret === '') return false;
        $given = $request->getHeaderLine('X-LGMS-Token');
        return $given !== '' && hash_equals($secret, $given);
    }

    /**
     * Returns a closure that writes a 403 response if the code does not
     * belong to the buyer, or null when ownership is confirmed.
     * When buyer_email is empty we skip the check (the WP plugin is trusted
     * to verify ownership before calling us).
     */
    private function ownershipError(int $purchasedBy, string $buyerEmail): ?\Closure
    {
        if ($buyerEmail === '') return null;

        $customer = $this->customers->findById($purchasedBy);
        if ($customer === null) {
            return static fn (Response $r) => self::json($r, ['error' => 'Buyer not found.'], 404);
        }
        if (!hash_equals(strtolower($customer->email), strtolower($buyerEmail))) {
            return static fn (Response $r) => self::json($r, ['error' => 'Code does not belong to this buyer.'], 403);
        }
        return null;
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
