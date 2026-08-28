<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Functional;

use Ubermuda\HealthCheckBundle\HealthMetadataProvider;

/** Stands in for the application-side provider that names the running build. */
final readonly class TestBuildMetadataProvider implements HealthMetadataProvider
{
    #[\Override]
    public function fields(): array
    {
        return ['build' => 'test'];
    }

    #[\Override]
    public function sensitive(): bool
    {
        return true;
    }
}
