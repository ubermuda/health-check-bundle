<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class UbermudaHealthCheckBundle extends AbstractBundle
{
    /** Translation domain of every key the bundle's own checks return. */
    public const string TRANSLATION_DOMAIN = 'ubermuda_health_check';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('path')
                    ->defaultValue('/healthz')
                    ->info('URL the health endpoint is mounted at, when the app imports config/routes.php.')
                ->end()
                ->scalarNode('probe_token')
                    ->defaultValue('')
                    ->info('Shared secret a caller presents as an X-Probe-Token header to receive sensitive metadata. Empty or null — the default, and what an unset environment variable resolves to — means sensitive fields never appear, whatever header is sent.')
                ->end()
            ->end();
    }

    /** @param array{path: string, probe_token: string|null} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('ubermuda_health_check.path', $config['path']);
        $builder->setParameter('ubermuda_health_check.probe_token', $config['probe_token']);

        $container->import('../config/services.php');
    }
}
