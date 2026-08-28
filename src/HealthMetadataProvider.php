<?php

declare(strict_types=1);

namespace Ubermuda\HealthCheckBundle;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contributes flat key/value facts about this instance to the health endpoint —
 * the build version, a region, a schema revision.
 *
 * A load balancer polls that endpoint every few seconds, so a provider must do
 * no I/O: no query, no HTTP call, no file read on every request. Anything that
 * has to look something up belongs in a DiagnosticInterface, which runs on a
 * page an operator opened.
 */
#[AutoconfigureTag('ubermuda_health_check.metadata')]
interface HealthMetadataProvider
{
    /**
     * Null-valued fields are dropped, so an absent value means the key simply
     * does not appear. `status` is the endpoint's own key and is refused.
     *
     * @return array<string, string|int|bool|null>
     */
    public function fields(): array;

    /** False only for data safe to hand an unauthenticated caller. */
    public function sensitive(): bool;
}
