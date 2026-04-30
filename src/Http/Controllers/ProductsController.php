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
     */
    public function list(Request $request, Response $response): Response
    {
        $tiers = array_map(
            static fn (array $t): array => ['min_qty' => $t['min'], 'discount_pct' => $t['pct']],
            $this->settings->getBulkDiscountTiers(),
        );

        $payload = [
            'products'            => $this->products->listMembership(),
            'bulk_discount_tiers' => $tiers,
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
