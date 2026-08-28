<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Ubermuda\HealthCheckBundle\Controller\ShowHealthController;

return static function (RoutingConfigurator $routes): void {
    $routes->add('ubermuda_health_check', '%ubermuda_health_check.path%')
        ->controller(ShowHealthController::class)
        ->methods(['GET']);
};
