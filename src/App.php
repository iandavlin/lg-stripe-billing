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
        // phpdotenv keeps values as strings; (bool) "false" is TRUE in PHP.
        // Match against literal "true"/"1"/"yes"/"on" so a typo can't leak
        // stack traces in production.
        $debug = in_array(strtolower((string) ($_ENV['APP_DEBUG'] ?? '')), ['true', '1', 'yes', 'on'], true);
        $app->addErrorMiddleware($debug, true, true);

        (require $rootDir . '/config/routes.php')($app);

        return $app;
    }
}
