<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use LGSB\Adapters\PdoPendingSessionRepository;
use LGSB\Contracts\SettingsStore;
use LGSB\Core\ReturnHandler;
use LGSB\Stripe\StripeGateway;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Cron-driven reconciliation of Stripe Checkout sessions whose browser-side
 * /v1/return never ran. Closes the "user paid but our DB has no entitlement"
 * gap that hits whenever the parent page is killed before Stripe redirects:
 * modal close, browser crash, network drop, phone sleep, etc.
 *
 * Auth: shared-secret header X-LGMS-Token (same scheme used by Slim → WP
 * sync calls in the other direction). Caller is the WP plugin's Tick::run.
 *
 * Algorithm per call:
 *   1. List unresolved pending_sessions older than 60s.
 *   2. For each, retrieve the session from Stripe.
 *      - status=complete  → run ReturnHandler::handle() (idempotent),
 *                             mark resolved.
 *      - status=expired   → mark resolved as 'abandoned' (no charge).
 *      - status=open      → leave; touch last_polled_at and skip.
 *   3. Prune resolved rows older than 7 days.
 *
 * Returns a small JSON summary the cron logger can write into tick.log
 * for visibility.
 */
final class ReconciliationController
{
    public function __construct(
        private readonly PdoPendingSessionRepository $pending,
        private readonly StripeGateway               $stripe,
        private readonly ReturnHandler               $returns,
        private readonly SettingsStore               $settings,
    ) {}

    /** POST /v1/reconcile-pending */
    public function reconcile(Request $request, Response $response): Response
    {
        $sharedSecret = (string) ($_ENV['LGMS_SHARED_SECRET'] ?? '');
        $supplied     = $request->getHeaderLine('X-LGMS-Token');
        if ($sharedSecret === '' || ! hash_equals($sharedSecret, $supplied)) {
            return self::json($response, ['error' => 'unauthorized'], 401);
        }

        $rows = $this->pending->listUnresolvedOlderThan(60);
        $stats = ['examined' => count($rows), 'recovered' => 0, 'abandoned' => 0, 'still_open' => 0, 'errors' => 0];
        $details = [];

        foreach ($rows as $row) {
            $sid = (string) $row['session_id'];
            try {
                $session = $this->stripe->retrieveCheckoutSession($sid, [
                    'subscription',
                    'subscription.items.data.price',
                    'setup_intent',
                    'setup_intent.payment_method',
                ]);
                $status = (string) ($session->status ?? '');

                if ($status === 'complete') {
                    $result = $this->returns->handle($sid);
                    if ($result['ok'] ?? false) {
                        $this->pending->markResolved($sid, 'recovered');
                        $stats['recovered']++;
                        $details[] = "{$sid} → recovered";
                    } else {
                        $this->pending->touchPolled($sid);
                        $stats['errors']++;
                        $details[] = "{$sid} → handler failed: " . ((string) ($result['message'] ?? 'unknown'));
                    }
                } elseif ($status === 'expired') {
                    $this->pending->markResolved($sid, 'abandoned');
                    $stats['abandoned']++;
                    $details[] = "{$sid} → abandoned";
                } else {
                    $this->pending->touchPolled($sid);
                    $stats['still_open']++;
                }
            } catch (Throwable $e) {
                $this->pending->touchPolled($sid);
                $stats['errors']++;
                $details[] = "{$sid} → {$e->getMessage()}";
                error_log("LGSB reconcile {$sid}: " . $e->getMessage());
            }
        }

        $pruned = $this->pending->pruneOlderThan(7);

        return self::json($response, [
            'ok'      => true,
            'stats'   => $stats,
            'pruned'  => $pruned,
            'details' => $details,
        ]);
    }

    private static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
