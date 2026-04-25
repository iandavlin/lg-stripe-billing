<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use LGSB\Contracts\SettingsStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public client config — what the browser needs to embed Stripe Checkout.
 * Only safe-to-publish values: the publishable key.
 */
final class ConfigController
{
    public function __construct(
        private readonly SettingsStore $settings,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode([
            'publishableKey' => $this->settings->getPublishableKey(),
        ], JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
