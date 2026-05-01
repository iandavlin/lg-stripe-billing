<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use LGSB\Contracts\SettingsStore;
use LGSB\Domain\Repositories\ProductRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ProductsController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly SettingsStore     $settings,
    ) {}

    /**
     * GET /v1/products
     *
     * Returns the membership product catalog plus the bulk discount tiers
     * so the [lg_gift] shortcode can render a live discount preview.
     *
     * Country detection (for regional pricing):
     *   1. ?country=XX query param (testing override)
     *   2. CF-IPCountry header (set by Cloudflare on every request)
     *   3. Falls back to no country -> default-region prices only
     */
    public function list(Request $request, Response $response): Response
    {
        $country = self::detectCountry($request);

        $tiers = array_map(
            static fn (array $t): array => ['min_qty' => $t['min'], 'discount_pct' => $t['pct']],
            $this->settings->getBulkDiscountTiers(),
        );

        $payload = [
            'products'            => $this->products->listMembership($country),
            'bulk_discount_tiers' => $tiers,
            'detected_country'    => $country,
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private static function detectCountry(Request $request): ?string
    {
        $params  = $request->getQueryParams();
        $override = isset($params['country']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $params['country'])) : '';
        if (strlen($override) === 2) {
            return $override;
        }
        $cf = strtoupper(trim($request->getHeaderLine('CF-IPCountry')));
        if (strlen($cf) === 2 && $cf !== 'XX' && $cf !== 'T1') {
            return $cf;
        }
        return null;
    }
}
