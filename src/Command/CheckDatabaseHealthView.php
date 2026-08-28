<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Command;

final readonly class CheckDatabaseHealthView
{
    public function __construct(
        public bool $healthy,
    ) {
    }
}
