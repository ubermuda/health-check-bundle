<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Functional;

/** An application that spelled "no token" as an explicit null. */
final class NullProbeTokenKernel extends HealthCheckTestKernel
{
    #[\Override]
    protected function probeToken(): ?string
    {
        return null;
    }
}
