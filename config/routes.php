<?php

declare(strict_types=1);

use LGSB\Http\Controllers\CheckoutController;
use LGSB\Http\Controllers\ConfigController;
use LGSB\Http\Controllers\HealthController;
use LGSB\Http\Controllers\ProductsController;
use LGSB\Http\Controllers\WebhookController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $app->get('/health', [HealthController::class, 'ping']);

    $app->group('/v1', function (RouteCollectorProxy $g): void {
        $g->get( '/config',   [ConfigController::class,   'get']);
        $g->get( '/products', [ProductsController::class, 'list']);
        $g->post('/checkout', [CheckoutController::class, 'create']);
        $g->post('/portal',   [CheckoutController::class, 'portal']);
        $g->get( '/return',   [CheckoutController::class, 'handleReturn']);
        $g->post('/webhook',  [WebhookController::class,  'handle']);
    });
};
