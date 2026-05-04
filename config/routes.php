<?php

declare(strict_types=1);

use LGSB\Http\Controllers\CheckoutController;
use LGSB\Http\Controllers\ConfigController;
use LGSB\Http\Controllers\GiftActionController;
use LGSB\Http\Controllers\HealthController;
use LGSB\Http\Controllers\ProductsController;
use LGSB\Http\Controllers\ReconciliationController;
use LGSB\Http\Controllers\RedeemController;
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
        $g->post('/redeem',   [RedeemController::class,   'redeem']);
        $g->post('/webhook',  [WebhookController::class,  'handle']);

        // Cron-driven reconciliation of orphaned Stripe sessions.
        // Auth via X-LGMS-Token; called from the WP plugin's Tick::run.
        $g->post('/reconcile-pending', [ReconciliationController::class, 'reconcile']);

        // Buyer gift management (server-to-server from WP plugin, X-LGMS-Token auth)
        $g->post('/gift-send',     [GiftActionController::class, 'send']);
        $g->post('/gift-resend',   [GiftActionController::class, 'resend']);
        $g->post('/gift-reassign', [GiftActionController::class, 'reassign']);
        $g->post('/gift-void',     [GiftActionController::class, 'void']);
    });
};
