<?php

declare(strict_types=1);

namespace LGSB\Http\Controllers;

use LGSB\Domain\Repositories\ProductRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ProductsController
{
    public function __construct(private readonly ProductRepository $products) {}

    /** GET /v1/products */
    public function list(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode(
            ['products' => $this->products->listMembership()],
            JSON_UNESCAPED_SLASHES,
        ));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
