<?php
declare(strict_types=1);

namespace LGSB\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    public function ping(Request $request, Response $response): Response
    {
        $response->getBody()->write(json_encode([
            'status'  => 'ok',
            'service' => 'lg-stripe-billing',
            'time'    => gmdate('c'),
            'env'     => $_ENV['APP_ENV'] ?? 'unknown',
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
