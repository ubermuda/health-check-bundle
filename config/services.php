<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->bind('$probeToken', param('ubermuda_health_check.probe_token'));

    $services->load('Ubermuda\\HealthCheckBundle\\', __DIR__.'/../src/')
        ->exclude([
            __DIR__.'/../src/Check/MessengerQueues.php',
            __DIR__.'/../src/Command/CheckDatabaseHealthCommand.php',
            __DIR__.'/../src/Command/*View.php',
            __DIR__.'/../src/Diagnostic.php',
            __DIR__.'/../src/DiagnosticState.php',
            __DIR__.'/../src/Testing/',
            __DIR__.'/../src/UbermudaHealthCheckBundle.php',
        ]);
};
