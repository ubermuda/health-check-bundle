<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Functional;

/**
 * The configuration the README recommends. `default::` resolves an unset or
 * blank variable to null, and does it when the container reads the parameter
 * rather than when it compiles — so nothing catches it before a probe arrives.
 */
final class EnvProbeTokenKernel extends HealthCheckTestKernel
{
    #[\Override]
    protected function probeToken(): string
    {
        return '%env(default::HEALTH_PROBE_TOKEN)%';
    }
}
