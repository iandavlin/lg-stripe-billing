<?php

declare(strict_types=1);

use LGSB\Http\Controllers\AffiliateController;
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

        // Affiliate management (server-to-server, X-LGMS-Token auth)
        $g->get( '/affiliates',        [AffiliateController::class, 'list']);
        $g->post('/affiliates',        [AffiliateController::class, 'create']);
        $g->get( '/affiliates/{id:\d+}/conversions', [AffiliateController::class, 'conversions']);

        // Public — browser fires this on landing when ?ref= is present
        $g->post('/affiliate-click',   [AffiliateController::class, 'click']);

        // Buyer gift management (server-to-server from WP plugin, X-LGMS-Token auth)
        $g->post('/gift-send',     [GiftActionController::class, 'send']);
        $g->post('/gift-resend',   [GiftActionController::class, 'resend']);
        $g->post('/gift-reassign', [GiftActionController::class, 'reassign']);
        $g->post('/gift-void',     [GiftActionController::class, 'void']);
    });
};
