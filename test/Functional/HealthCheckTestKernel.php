<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Ubermuda\HealthCheckBundle\UbermudaHealthCheckBundle;

class HealthCheckTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new UbermudaHealthCheckBundle(),
        ];
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/ubermuda-health-check/cache/'.$this->environment;
    }

    #[\Override]
    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/ubermuda-health-check/log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'mailer' => ['dsn' => 'null://null'],
            'default_locale' => 'en',
            'translator' => ['fallbacks' => ['en']],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'url' => 'sqlite:///:memory:',
            ],
        ]);

        $container->extension('ubermuda_health_check', [
            'probe_token' => 'right-token',
        ]);

        $container->services()
            ->set(TestBuildMetadataProvider::class)
            ->autoconfigure();

        // The checks log what they could not read; this kernel has no
        // messenger_messages table, so without this the suite's output is that
        // expected warning over and over.
        $container->services()->set('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__.'/../../config/routes.php');
    }
}
