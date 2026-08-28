<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle\Test\Support;

use Ubermuda\HealthCheckBundle\HealthMetadataProvider;

final readonly class StaticMetadataProvider implements HealthMetadataProvider
{
    /** @param array<string, string|int|bool|null> $fields */
    public function __construct(
        private array $fields,
        private bool $sensitive = true,
    ) {
    }

    #[\Override]
    public function fields(): array
    {
        return $this->fields;
    }

    #[\Override]
    public function sensitive(): bool
    {
        return $this->sensitive;
    }
}
