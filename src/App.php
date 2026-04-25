<?php
declare(strict_types=1);

namespace LGSB;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

final class App
{
    public static function create(string $rootDir): SlimApp
    {
        if (file_exists($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions(require $rootDir . '/config/container.php');
        $container = $containerBuilder->build();

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $basePath = (string) ($_ENV['APP_BASE_PATH'] ?? '');
        if ($basePath !== '') {
            $app->setBasePath('/' . trim($basePath, '/'));
        }

        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $app->addErrorMiddleware(
            (bool) ($_ENV['APP_DEBUG'] ?? false),
            true,
            true,
        );

        (require $rootDir . '/config/routes.php')($app);

        return $app;
    }
}
