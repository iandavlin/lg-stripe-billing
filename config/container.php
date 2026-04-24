<?php

declare(strict_types=1);

use LGSB\Adapters\EnvSettingsStore;
use LGSB\Adapters\LiveStripeGateway;
use LGSB\Adapters\PdoCustomerRepository;
use LGSB\Adapters\PdoEntitlementRepository;
use LGSB\Adapters\PdoProductRepository;
use LGSB\Adapters\PdoSubscriptionRepository;
use LGSB\Contracts\SettingsStore;
use LGSB\Domain\Repositories\CustomerRepository;
use LGSB\Domain\Repositories\EntitlementRepository;
use LGSB\Domain\Repositories\ProductRepository;
use LGSB\Domain\Repositories\SubscriptionRepository;
use LGSB\Stripe\StripeGateway;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [

    /* ---------------- infrastructure ---------------- */

    LoggerInterface::class => function (): LoggerInterface {
        $logger  = new Logger('lgsb');
        $logPath = dirname(__DIR__) . '/logs/app.log';
        if (! is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0775, true);
        }
        $logger->pushHandler(new StreamHandler($logPath, Logger::DEBUG));
        return $logger;
    },

    PDO::class => function (): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'] ?? '127.0.0.1',
            $_ENV['DB_PORT'] ?? '3306',
            $_ENV['DB_NAME'] ?? '',
        );
        return new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASSWORD'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    },

    /* ---------------- contracts → adapters ---------------- */

    SettingsStore::class => fn (): SettingsStore => new EnvSettingsStore(),

    StripeGateway::class => fn (ContainerInterface $c): StripeGateway =>
        new LiveStripeGateway($c->get(SettingsStore::class)->getSecretKey()),

    CustomerRepository::class => fn (ContainerInterface $c): CustomerRepository =>
        new PdoCustomerRepository($c->get(PDO::class)),

    SubscriptionRepository::class => fn (ContainerInterface $c): SubscriptionRepository =>
        new PdoSubscriptionRepository($c->get(PDO::class)),

    EntitlementRepository::class => fn (ContainerInterface $c): EntitlementRepository =>
        new PdoEntitlementRepository($c->get(PDO::class)),

    ProductRepository::class => fn (ContainerInterface $c): ProductRepository =>
        new PdoProductRepository($c->get(PDO::class)),

    /* Core services (CheckoutService, CustomerManager, EntitlementManager,
       ReturnHandler) and HTTP controllers are autowired by PHP-DI from
       their constructor signatures — nothing to declare here. */
];
