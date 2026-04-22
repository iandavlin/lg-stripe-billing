<?php
declare(strict_types=1);

use LGSB\Http\Controllers\HealthController;
use Slim\App;

return function (App $app): void {
    $app->get('/health', [HealthController::class, 'ping']);

    // TODO: port from plugin
    // $app->post('/checkout', [CheckoutController::class, 'create']);
    // $app->get('/return',    [CheckoutController::class, 'handleReturn']);
    // $app->post('/portal',   [CheckoutController::class, 'portal']);
    // $app->post('/webhook',  [WebhookController::class, 'handle']);
};
